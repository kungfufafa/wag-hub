<?php

namespace App\Services\Connections;

use App\Exceptions\WhatsAppConnectionException;
use App\Exceptions\WhatsAppEngineException;
use App\Models\EngineMessage;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use App\Models\RoutingPolicy;
use App\Models\WhatsAppConnection;
use App\Services\Engine\EngineTransportService;
use App\Services\GatewayMessageDispatcher;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Sends one message through a connection.
 *
 * A managed number delegates to EngineTransportService::accept, so it has exactly the session
 * pinning, idempotency and media semantics of POST /api/v2/sessions/{id}/messages (202, queued).
 * A provider route goes through the routed gateway pipeline (GatewayMessageDispatcher) and is
 * dispatched synchronously: 201 once a provider accepted it. In both cases an Idempotency-Key
 * replay is answered from the stored message before any readiness check.
 */
final class ConnectionMessenger
{
    public function __construct(
        private EngineTransportService $transport,
        private GatewayMessageDispatcher $dispatcher,
        private PhoneNormalizer $phones,
        private ApplicationErrorMapper $errors,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  Validated SendEngineMessageRequest input: to, type, text, media_id, expires_at.
     */
    public function send(WhatsAppConnection $connection, array $payload, string $key): ConnectionDelivery
    {
        return $connection->isManaged()
            ? $this->sendManaged($connection, $payload, $key)
            : $this->sendRouted($connection, $payload, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendManaged(WhatsAppConnection $connection, array $payload, string $key): ConnectionDelivery
    {
        $session = $connection->ownedEngineSession();
        // A replay is answered against the session that accepted the original message, so a key
        // stays a replay after a removed session was replaced through connect/retry.
        $sessionUuid = $this->replayedSessionUuid($connection, $key) ?? $session?->uuid;

        if ($sessionUuid === null) {
            throw new WhatsAppConnectionException('WhatsApp connection is not ready.', 409, 'connection_not_ready', true);
        }

        try {
            // A removed session still answers replays: accept looks the key up before the session.
            $message = $this->transport->accept($connection->clientApplication, $sessionUuid, $payload, $key);
        } catch (WhatsAppEngineException $exception) {
            if ($exception->errorCode === 'session_not_found') {
                throw new WhatsAppConnectionException('WhatsApp connection has no active session. Reconnect it.', 409, 'connection_not_ready');
            }

            throw $exception;
        }

        return new ConnectionDelivery(
            ['connection_id' => (string) $connection->uuid, ...$this->transport->snapshot($message)],
            202,
        );
    }

    /**
     * The session of an earlier message accepted under this key, when that session belongs (or
     * belonged) to this connection. Any other stored message keeps hashing against the current
     * session, so reusing its key here stays an idempotency_conflict.
     */
    private function replayedSessionUuid(WhatsAppConnection $connection, string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        $existing = EngineMessage::query()
            ->where('client_application_id', $connection->client_application_id)
            ->where('idempotency_key', $key)
            ->first(['engine_session_id', 'session_uuid']);

        if ($existing === null || ! in_array((int) $existing->engine_session_id, $connection->engineSessionHistory(), true)) {
            return null;
        }

        return (string) $existing->session_uuid;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sendRouted(WhatsAppConnection $connection, array $payload, string $key): ConnectionDelivery
    {
        $this->assertKey($key);

        try {
            $recipient = $this->phones->normalize((string) ($payload['to'] ?? ''));
        } catch (InvalidArgumentException) {
            throw new WhatsAppEngineException('Recipient must use an international phone number.', 422, 'invalid_phone');
        }

        $type = (string) ($payload['type'] ?? 'text');
        $text = (string) ($payload['text'] ?? '');
        $expiresAt = isset($payload['expires_at']) ? CarbonImmutable::parse((string) $payload['expires_at']) : null;
        $hash = hash('sha256', json_encode([
            'v2-connection', (string) $connection->uuid, $type, $recipient, $text,
            $payload['media_id'] ?? null, $expiresAt?->utc()->toISOString(),
        ], JSON_THROW_ON_ERROR));

        // Replay precedes readiness and capability checks: a retry is a read even after an outage.
        $existing = $this->existing($connection, $key);

        if ($existing !== null) {
            return $this->routedResult($connection, $existing, $hash);
        }

        if ($type !== 'text') {
            throw new WhatsAppConnectionException(
                'Provider-route connections send text through the Connection API; use a managed number for media.',
                422,
                'capability_not_supported',
            );
        }

        if (trim($text) === '') {
            throw new WhatsAppEngineException('Message type or text is invalid.', 422, 'invalid_message');
        }

        if ($expiresAt !== null && $expiresAt->isPast()) {
            throw new WhatsAppEngineException('Message expiry must be in the future.', 422, 'invalid_expiry');
        }

        if (! $connection->canAttemptSend() || ! $this->hasUsablePolicy($connection)) {
            throw new WhatsAppConnectionException('WhatsApp connection is not ready.', 409, 'connection_not_ready', true);
        }

        try {
            $message = DB::transaction(function () use ($connection, $key, $hash, $recipient, $text, $expiresAt): GatewayMessage {
                $message = $this->createMessage($connection, $key, $hash, $recipient, $text, $expiresAt);
                $event = new MessageEvent;
                $event->forceFill([
                    'gateway_message_id' => $message->getKey(),
                    'type' => 'processing',
                    'source' => 'api',
                    'data' => null,
                    'occurred_at' => now(),
                ])->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $winner = $this->existing($connection, $key) ?? throw $exception;

            return $this->routedResult($connection, $winner, $hash);
        }

        $dispatched = $this->dispatcher->dispatch($message);

        return $this->routedResult($connection, $dispatched->fresh() ?? $dispatched, $hash);
    }

    /**
     * A connection sends only through its own routing policy. When that policy is inactive or gone
     * the message must not fall back to the application's default (another connection's provider).
     */
    private function hasUsablePolicy(WhatsAppConnection $connection): bool
    {
        if ($connection->routing_policy_id === null) {
            return false;
        }

        return RoutingPolicy::query()
            ->whereKey($connection->routing_policy_id)
            ->where('client_application_id', $connection->client_application_id)
            ->where('operation', 'message')
            ->where('is_active', true)
            ->exists();
    }

    private function existing(WhatsAppConnection $connection, string $key): ?GatewayMessage
    {
        return GatewayMessage::query()
            ->where('client_application_id', $connection->client_application_id)
            ->where('idempotency_key', $key)
            ->first();
    }

    private function createMessage(
        WhatsAppConnection $connection,
        string $key,
        string $hash,
        string $recipient,
        string $text,
        ?CarbonImmutable $expiresAt,
    ): GatewayMessage {
        $now = now();
        $message = new GatewayMessage;
        $message->forceFill([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $connection->client_application_id,
            'whatsapp_connection_id' => $connection->getKey(),
            'routing_policy_id' => $connection->routing_policy_id,
            'accepted_provider_account_id' => null,
            'idempotency_key' => $key,
            'payload_hash' => $hash,
            'correlation_id' => (string) Str::uuid(),
            'client_reference' => null,
            'recipient' => $recipient,
            'recipient_hash' => hash_hmac('sha256', $recipient, (string) config('app.key')),
            'recipient_last4' => substr($recipient, -4),
            'body' => $text,
            'message_type' => 'text',
            'attachment' => null,
            'purpose' => 'notification',
            'route_key' => (string) ($connection->routingPolicy?->key ?? 'default'),
            'mode' => 'sync',
            'origin' => 'api',
            'priority' => 10,
            'status' => 'processing',
            'metadata' => [],
            'expires_at' => $expiresAt?->setTimezone(config('app.timezone')),
            'queued_at' => null,
            'processing_at' => $now,
        ]);
        $message->save();

        return $message;
    }

    private function routedResult(WhatsAppConnection $connection, GatewayMessage $message, string $hash): ConnectionDelivery
    {
        if (! hash_equals((string) $message->payload_hash, $hash)) {
            throw new WhatsAppEngineException('Idempotency key was already used for a different message.', 409, 'idempotency_conflict');
        }

        $data = $this->routedSnapshot($connection, $message);
        $status = (string) $message->status;

        if ($status === 'provider_accepted') {
            return new ConnectionDelivery($data, 201);
        }

        if (in_array($status, ['queued', 'processing'], true)) {
            return new ConnectionDelivery($data, 202);
        }

        [$http, $code, $retryable, $text] = match ($status) {
            'outcome_unknown' => [502, 'provider_outcome_unknown', false, 'The provider outcome could not be determined.'],
            'expired' => [410, 'message_expired', false, 'The message expired before it could be sent.'],
            default => in_array($message->last_error_code, ['route_unavailable', 'connection_not_ready'], true)
                ? [503, 'route_unavailable', true, 'No usable route is currently available.']
                : [503, 'providers_failed', true, 'No provider accepted the message.'],
        };

        return new ConnectionDelivery($data, $http, $this->errors->forConnection($code, $retryable), $text);
    }

    /**
     * @return array<string, mixed>
     */
    private function routedSnapshot(WhatsAppConnection $connection, GatewayMessage $message): array
    {
        return [
            'connection_id' => (string) $connection->uuid,
            'id' => (string) $message->uuid,
            'session_id' => null,
            'type' => (string) ($message->message_type ?: 'text'),
            'transport_status' => match ((string) $message->status) {
                'provider_accepted' => 'accepted',
                'queued', 'processing' => 'queued',
                'outcome_unknown' => 'outcome_unknown',
                'expired' => 'expired',
                default => 'failed',
            },
            'receipt_status' => null,
            'provider_message_id' => $message->provider_message_id,
            'error_code' => $message->last_error_code,
            'expires_at' => $message->expires_at?->toISOString(),
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
        ];
    }

    private function assertKey(string $key): void
    {
        if ($key === '' || strlen($key) > 160 || trim($key) !== $key || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new WhatsAppEngineException('A valid Idempotency-Key header is required.', 422, 'invalid_idempotency_key');
        }
    }
}
