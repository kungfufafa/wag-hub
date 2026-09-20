<?php

namespace App\Services\Engine;

use App\Exceptions\WhatsAppEngineException;
use App\Models\ClientApplication;
use App\Models\EngineEvent;
use App\Models\EngineIntegration;
use App\Models\EngineMessage;
use App\Models\EngineSession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

class EngineEventService
{
    public function __construct(private RunnerClient $runner, private EngineMediaService $media, private WebhookDestination $destination) {}

    public function publishSession(EngineSession $session): EngineEvent
    {
        return DB::transaction(fn (): EngineEvent => $this->record($session, 'session.status',
            app(SessionService::class)->snapshot($session), now()->toISOString()));
    }

    public function ingestRunnerEvent(array $event): ?EngineEvent
    {
        $source = (string) ($event['id'] ?? '');
        if ($source === '' || strlen($source) > 100 || ! in_array($event['event'] ?? '', ['message', 'message.ack', 'session.status'], true)) {
            throw new WhatsAppEngineException('Runner event is invalid.', 422, 'invalid_runner_event');
        }
        $existing = EngineEvent::query()->where('source_event_id', $source)->first();
        if ($existing !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($event, $source): ?EngineEvent {
            // Keep tombstone ownership for already handed-off messages and ACKs after deletion.
            $session = EngineSession::withTrashed()->where('runner_id', $event['session_id'] ?? '')
                ->lockForUpdate()->first();
            if ($session === null) {
                return null;
            }
            $existing = EngineEvent::query()->where('source_event_id', $source)->first();
            if ($existing !== null) {
                return $existing;
            }
            $data = is_array($event['data'] ?? null) ? $event['data'] : [];
            if ($event['event'] === 'session.status') {
                if ($session->trashed() || (int) ($event['generation'] ?? -1) !== (int) $session->generation
                    || (int) ($event['revision'] ?? 0) <= (int) $session->runner_revision) {
                    return null;
                }
                $observedAt = CarbonImmutable::parse($event['occurred_at'] ?? 'now');
                if ($observedAt->isFuture()) {
                    $observedAt = CarbonImmutable::now();
                }
                $session = app(SessionService::class)->observe($session, [
                    ...$data, 'generation' => (int) $event['generation'], 'revision' => (int) $event['revision'],
                    'phone' => $data['phone_number'] ?? $data['phone'] ?? null,
                ], $observedAt);
                $data = app(SessionService::class)->snapshot($session);
            } elseif ($event['event'] === 'message') {
                $attachment = null;
                try {
                    $attachment = $this->media->incoming(ClientApplication::withTrashed()->findOrFail($session->client_application_id), $data);
                } catch (WhatsAppEngineException $exception) {
                    if ($exception->statusCode >= 500) {
                        // The runner retains the bytes until a successful commit and explicit ACK.
                        throw $exception;
                    }
                    $data['media_error'] = $exception->errorCode;
                }
                unset($data['media_base64']);
                if ($attachment !== null) {
                    $data['media_id'] = $attachment->uuid;
                    $data['media'] = $this->media->snapshot($attachment);
                    $data['mimetype'] = $attachment->mimetype;
                    $data['filename'] = $attachment->filename;
                }
            } else {
                $data = $this->applyAcknowledgement($session, $data);
            }
            // Internal secrets and bytes can never cross the public webhook boundary.
            unset($data['qr'], $data['pairing_code'], $data['credentials'], $data['media_base64']);

            return $this->record($session, $event['event'], $data,
                (string) ($event['occurred_at'] ?? now()->toISOString()), $source);
        });
    }

    public function pollRunner(int $limit = 100): int
    {
        // The runner removes only explicitly ACKed entries. Always read its oldest pending page.
        $response = $this->runner->request('GET', '/events?after=0&limit='.min(100, max(1, $limit)));
        $count = 0;
        foreach (array_slice($response['events'] ?? [], 0, $limit) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $this->ingestRunnerEvent($event);
            // A lost ACK only causes deduplicated redelivery; persisted events are never lost.
            $this->runner->request('DELETE', '/events/'.rawurlencode((string) $event['id']));
            $count++;
        }

        return $count;
    }

    public function envelope(EngineEvent $event): array
    {
        $applicationUuid = ClientApplication::withTrashed()->whereKey($event->client_application_id)->value('uuid');

        return ['schema_version' => '2.0', 'id' => $event->uuid, 'event' => $event->event, 'installation_id' => $applicationUuid,
            'session_id' => $event->session_uuid, 'revision' => $event->revision,
            'occurred_at' => $event->occurred_at->toISOString(), 'data' => $event->data];
    }

    public function replay(ClientApplication $application, int $after = 0, int $limit = 100): array
    {
        if ($after < 0) {
            throw new WhatsAppEngineException('Event cursor is invalid.', 422, 'invalid_cursor');
        }
        if ($after > 0 && ! EngineEvent::query()->where('client_application_id', $application->id)->whereKey($after)
            ->where('expires_at', '>', now())->exists()) {
            throw new WhatsAppEngineException('Event cursor has expired or is invalid. Refresh the authoritative session snapshot.', 410, 'event_cursor_expired');
        }
        $events = EngineEvent::query()->where('client_application_id', $application->id)->where('id', '>', $after)
            ->where('expires_at', '>', now())->orderBy('id')->limit(min(100, max(1, $limit)) + 1)->get();
        $hasMore = $events->count() > min(100, max(1, $limit));
        $page = $events->take(min(100, max(1, $limit)));

        return ['events' => $page->map(fn (EngineEvent $event): array => $this->envelope($event))->values()->all(),
            'next_cursor' => (string) ($page->last()?->id ?? $after), 'has_more' => $hasMore];
    }

    public function deliver(int $eventId): void
    {
        $event = DB::transaction(function () use ($eventId): ?EngineEvent {
            $event = EngineEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if ($event === null || $event->delivered_at !== null || $event->failed_at !== null
                || ($event->next_attempt_at !== null && $event->next_attempt_at->isFuture())
                || ($event->delivery_locked_until !== null && $event->delivery_locked_until->isFuture())) {
                return null;
            }
            if ($event->created_at->copy()->addHours((int) config('engine_v2.webhook_retry_hours', 24))->lessThanOrEqualTo(now())
                || $event->expires_at->lessThanOrEqualTo(now())) {
                $event->update(['failed_at' => now(), 'delivery_error' => 'retry_window_exhausted', 'next_attempt_at' => null]);

                return null;
            }
            $integration = EngineIntegration::query()->where('client_application_id', $event->client_application_id)->first();
            if ($integration === null || ! $integration->webhook_enabled || ! $integration->webhook_url || ! $integration->webhook_secret) {
                return null;
            }
            if (! ClientApplication::query()->whereKey($event->client_application_id)->where('is_active', true)->exists()) {
                return null;
            }
            $event->update(['delivery_attempts' => $event->delivery_attempts + 1, 'delivery_locked_until' => now()->addSeconds(60)]);

            return $event;
        });
        if ($event === null) {
            return;
        }
        $integration = EngineIntegration::query()->where('client_application_id', $event->client_application_id)->firstOrFail();
        $error = null;
        try {
            $body = json_encode($this->envelope($event), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) now()->timestamp;
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, $integration->webhook_secret);
            $response = Http::withOptions($this->destination->options($integration->webhook_url))->withoutRedirecting()
                ->connectTimeout(3)->timeout(10)->withHeaders([
                    'X-Wag-Timestamp' => $timestamp, 'X-Wag-Signature' => $signature, 'X-Wag-Event-ID' => $event->uuid,
                ])->withBody($body, 'application/json')->post($integration->webhook_url);
            if (! $response->successful()) {
                $error = 'http_'.$response->status();
            }
        } catch (WhatsAppEngineException $exception) {
            $error = $exception->errorCode;
        } catch (Throwable) {
            $error = 'webhook_unreachable';
        }
        if ($error === null) {
            $event->update(['delivered_at' => now(), 'delivery_locked_until' => null, 'next_attempt_at' => null, 'delivery_error' => null]);
            $integration->update(['webhook_last_success_at' => now(), 'webhook_last_error' => null]);
        } else {
            $base = min(900, 15 * (2 ** min(6, max(0, $event->delivery_attempts - 1))));
            $seconds = min(900, $base + random_int(0, max(1, (int) floor($base / 4))));
            $event->update(['delivery_locked_until' => null, 'next_attempt_at' => now()->addSeconds($seconds), 'delivery_error' => $error]);
            $integration->update(['webhook_last_error_at' => now(), 'webhook_last_error' => $error]);
        }
    }

    private function record(EngineSession $session, string $name, array $data, string $occurredAt, ?string $source = null): EngineEvent
    {
        return EngineEvent::query()->create([
            'source_event_id' => $source, 'client_application_id' => $session->client_application_id,
            'engine_session_id' => $session->id, 'session_uuid' => $session->uuid, 'event' => $name,
            'revision' => $session->revision, 'data' => $data, 'occurred_at' => $occurredAt,
            'expires_at' => now()->addDays((int) config('engine_v2.event_retention_days', 7)), 'next_attempt_at' => now(),
        ]);
    }

    private function applyAcknowledgement(EngineSession $session, array $data): array
    {
        $providerId = (string) ($data['message_id'] ?? '');
        if ($providerId === '' || ($data['from_me'] ?? false) !== true) {
            return $data;
        }
        $message = EngineMessage::query()->where('engine_session_id', $session->id)->where('provider_message_id', $providerId)
            ->lockForUpdate()->first();
        if ($message === null) {
            // The ACK may beat the POST response; correlate the runner's stable key when supplied.
            $key = $data['idempotency_key'] ?? null;
            if (is_string($key)) {
                $message = EngineMessage::query()->where('engine_session_id', $session->id)->where('uuid', $key)->lockForUpdate()->first();
            }
        }
        if ($message !== null) {
            $ack = $data['ack'] ?? null;
            $rank = ['delivered' => 1, 'read' => 2, 'played' => 3];
            $receipt = $message->receipt_status;
            if (($rank[$ack] ?? 0) > ($rank[$receipt] ?? 0)) {
                $receipt = $ack;
            }
            if (in_array($ack, ['accepted', 'delivered', 'read', 'played'], true)) {
                $message->update(['transport_status' => 'accepted', 'receipt_status' => $receipt,
                    'provider_message_id' => $providerId, 'error_code' => null, 'reconciled_at' => now()]);
            }
            $data['message_id'] = $message->uuid;
            $data['provider_message_id'] = $providerId;
            $data['transport_status'] = $message->transport_status;
            $data['receipt_status'] = $message->receipt_status;
        }

        return $data;
    }
}
