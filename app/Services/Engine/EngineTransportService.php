<?php

namespace App\Services\Engine;

use App\Exceptions\WhatsAppEngineException;
use App\Jobs\EngineDispatchMessage;
use App\Models\ClientApplication;
use App\Models\EngineMessage;
use App\Models\EngineSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class EngineTransportService
{
    public function __construct(private RunnerClient $runner, private EngineMediaService $media) {}

    public function accept(ClientApplication $application, string $sessionUuid, array $payload, string $key): EngineMessage
    {
        if ($key === '' || strlen($key) > 160 || trim($key) !== $key || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new WhatsAppEngineException('A valid Idempotency-Key header is required.', 422, 'invalid_idempotency_key');
        }
        $phone = ltrim((string) ($payload['to'] ?? ''), '+');
        if (! preg_match('/\A[1-9][0-9]{7,14}\z/', $phone)) {
            throw new WhatsAppEngineException('Recipient must use an international phone number.', 422, 'invalid_phone');
        }
        $type = (string) ($payload['type'] ?? 'text');
        $normalized = ['type' => $type, 'to' => $phone, 'text' => (string) ($payload['text'] ?? ''),
            'media_id' => $payload['media_id'] ?? null,
            'expires_at' => isset($payload['expires_at']) ? CarbonImmutable::parse($payload['expires_at'])->toISOString() : null];
        $hash = hash('sha256', json_encode([$sessionUuid, $normalized], JSON_THROW_ON_ERROR));
        // Lookup precedes session/media availability checks: a retry is a read even after logout or expiry.
        $existing = EngineMessage::query()->where('client_application_id', $application->id)->where('idempotency_key', $key)->first();
        if ($existing !== null) {
            $this->samePayload($existing, $hash);

            return $existing;
        }
        $expiresAt = $normalized['expires_at'] !== null ? CarbonImmutable::parse($normalized['expires_at']) : now()->addHours(24);
        if ($expiresAt->isPast()) {
            throw new WhatsAppEngineException('Message expiry must be in the future.', 422, 'invalid_expiry');
        }
        if (! in_array($type, ['text', 'image', 'document', 'voice'], true)
            || ($type === 'text' && trim($normalized['text']) === '') || mb_strlen($normalized['text']) > 10000
            || ($type === 'text' && $normalized['media_id'] !== null)) {
            throw new WhatsAppEngineException('Message type or text is invalid.', 422, 'invalid_message');
        }
        $attachment = $type === 'text' ? null : $this->media->owned($application, (string) $normalized['media_id']);
        if ($attachment !== null && $attachment->type !== $type) {
            throw new WhatsAppEngineException('Uploaded media does not match the message type.', 422, 'invalid_media_type');
        }
        try {
            $message = DB::transaction(function () use ($application, $sessionUuid, $normalized, $key, $hash, $attachment, $expiresAt): EngineMessage {
                $session = EngineSession::query()->where('client_application_id', $application->id)
                    ->where('uuid', $sessionUuid)->lockForUpdate()->first();
                if ($session === null) {
                    throw new WhatsAppEngineException('Session not found.', 404, 'session_not_found');
                }
                if ($session->desired_state !== 'RUNNING' || $session->status !== 'WORKING' || ! $session->engine_available || $session->pending_operation_id !== null
                    || ! $session->observed_at || $session->observed_at->lt(now()->subSeconds((int) config('engine_v2.stale_after_seconds', 30)))) {
                    throw new WhatsAppEngineException('Session is not ready to send messages.', 409, 'session_not_working');
                }

                return EngineMessage::query()->create([
                    'client_application_id' => $application->id, 'engine_session_id' => $session->id,
                    'session_uuid' => $session->uuid, 'runner_id' => $session->runner_id, 'generation' => $session->generation,
                    'idempotency_key' => $key, 'payload_hash' => $hash, 'type' => $normalized['type'],
                    'recipient' => $normalized['to'], 'payload' => $normalized, 'engine_media_id' => $attachment?->id,
                    'transport_status' => 'queued', 'expires_at' => $expiresAt,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $message = EngineMessage::query()->where('client_application_id', $application->id)->where('idempotency_key', $key)->firstOrFail();
            $this->samePayload($message, $hash);

            return $message;
        }
        // Queue delivery is an optimization; the scheduler recovers durable queued rows if enqueue fails.
        try {
            EngineDispatchMessage::dispatch($message->id)->onConnection(config('engine_v2.queue_connection', 'database'))->onQueue('engine')->afterCommit();
        } catch (Throwable) {
            report(new \RuntimeException('Engine message persisted; dispatch will be recovered by the transport scheduler.'));
        }

        return $message;
    }

    public function dispatch(int $messageId): void
    {
        $reference = EngineMessage::query()->find($messageId);
        if ($reference === null) {
            return;
        }
        $message = DB::transaction(function () use ($messageId, $reference): ?EngineMessage {
            // Match ACK ingestion's session-before-message order to avoid deadlocks.
            $session = EngineSession::query()->whereKey($reference->engine_session_id)->lockForUpdate()->first();
            $message = EngineMessage::query()->whereKey($messageId)->lockForUpdate()->first();
            if ($message === null || $message->transport_status !== 'queued') {
                return null;
            }
            if ($message->expires_at->isPast()) {
                $message->update(['transport_status' => 'expired', 'error_code' => 'message_expired']);

                return null;
            }
            if ($session === null || $session->client_application_id !== $message->client_application_id
                || $session->desired_state !== 'RUNNING' || $session->status !== 'WORKING'
                || $session->pending_operation_id !== null || (int) $session->generation !== $message->generation) {
                $message->update(['transport_status' => 'failed', 'error_code' => 'session_changed']);

                return null;
            }
            $message->update(['transport_status' => 'processing', 'processing_at' => now()]);

            return $message;
        });
        if ($message === null) {
            return;
        }
        try {
            $body = ['type' => $message->type, 'phone' => $message->recipient,
                'idempotency_key' => $message->uuid, 'generation' => $message->generation];
            if ($message->type === 'text') {
                $body['text'] = $message->payload['text'];
            } else {
                $attachment = $message->media;
                if ($attachment === null || $attachment->expires_at->isPast()) {
                    $this->applyResult($message, ['status' => 'failed', 'error_code' => 'media_expired']);

                    return;
                }
                // Read and verify before any runner request: media failure is a definite non-send.
                try {
                    $body += ['media_base64' => base64_encode($this->media->bytes($attachment)),
                        'filename' => $attachment->filename, 'mimetype' => $attachment->mimetype,
                        'caption' => $message->payload['text']];
                } catch (Throwable) {
                    $this->applyResult($message, ['status' => 'failed', 'error_code' => 'media_unavailable']);

                    return;
                }
            }
            $result = $this->runner->request('POST', '/sessions/'.$message->runner_id.'/messages', $body);
            $this->applyResult($message, $result);
        } catch (Throwable) {
            $this->applyResult($message, ['status' => 'unknown', 'error_code' => 'delivery_unknown']);
        }
    }

    public function reconcile(EngineMessage $message): EngineMessage
    {
        if (! in_array($message->transport_status, ['processing', 'outcome_unknown'], true)) {
            return $message;
        }
        try {
            $result = $this->runner->request('GET', '/sessions/'.$message->runner_id.'/messages/'.rawurlencode($message->uuid));
            if (in_array($result['error_code'] ?? null, ['message_not_found', 'not_found'], true)) {
                $result = ['status' => 'unknown', 'error_code' => 'delivery_unknown'];
            }
            $this->applyResult($message, $result);
        } catch (Throwable) {
            // Not-found cannot prove a crashed send never escaped. Only query; never resend.
            $this->applyResult($message, ['status' => 'unknown', 'error_code' => 'delivery_unknown']);
        }

        return $message->fresh();
    }

    public function owned(ClientApplication $application, string $uuid): EngineMessage
    {
        $message = EngineMessage::query()->where('client_application_id', $application->id)->where('uuid', $uuid)->first();
        if ($message === null) {
            throw new WhatsAppEngineException('Message not found.', 404, 'message_not_found');
        }

        return $message;
    }

    public function snapshot(EngineMessage $message): array
    {
        return ['id' => $message->uuid, 'session_id' => $message->session_uuid, 'type' => $message->type,
            'transport_status' => $message->transport_status, 'receipt_status' => $message->receipt_status,
            'provider_message_id' => $message->provider_message_id, 'error_code' => $message->error_code,
            'expires_at' => $message->expires_at->toISOString(),
            'created_at' => $message->created_at->toISOString(), 'updated_at' => $message->updated_at->toISOString()];
    }

    private function samePayload(EngineMessage $message, string $hash): void
    {
        if (! hash_equals($message->payload_hash, $hash)) {
            throw new WhatsAppEngineException('Idempotency key was already used for a different message.', 409, 'idempotency_conflict');
        }
    }

    private function applyResult(EngineMessage $message, array $result): void
    {
        DB::transaction(function () use ($message, $result): void {
            $current = EngineMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            // ACKs may race the HTTP response. Never regress a confirmed acceptance or receipt.
            if ($current->transport_status === 'accepted') {
                return;
            }
            $status = match ($result['status'] ?? null) {
                'sent' => is_string($result['id'] ?? null) && $result['id'] !== '' ? 'accepted' : 'outcome_unknown',
                'failed' => 'failed', default => 'outcome_unknown',
            };
            $current->update(['transport_status' => $status,
                'provider_message_id' => $result['id'] ?? $current->provider_message_id,
                'error_code' => $status === 'accepted' ? null : ($result['error_code'] ?? 'delivery_unknown'),
                'reconciled_at' => now()]);
        });
    }
}
