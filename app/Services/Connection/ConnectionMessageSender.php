<?php

namespace App\Services\Connection;

use App\Domain\Connection\ConnectionCapability;
use App\Domain\Delivery\OutboundAttachment;
use App\Exceptions\ConnectionException;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use App\Models\WhatsAppConnection;
use App\Services\AttachmentService;
use App\Services\GatewayMessageDispatcher;
use App\Services\GatewayMessageEnqueuer;
use App\Support\PayloadHasher;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConnectionMessageSender
{
    public function __construct(
        private readonly ConnectionCapabilityResolver $capabilities,
        private readonly ConnectionStatusResolver $statusResolver,
        private readonly GatewayMessageEnqueuer $enqueuer,
        private readonly GatewayMessageDispatcher $dispatcher,
        private readonly PhoneNormalizer $phones,
        private readonly PayloadHasher $hasher,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function send(
        ClientApplication $application,
        array $payload,
        string $idempotencyKey,
        string $payloadHash,
        string $correlationId,
        ?WhatsAppConnection $connection = null,
    ): GatewayMessage {
        $connection ??= $this->resolveDefaultConnection($application);

        if ($connection === null) {
            throw new ConnectionException(
                'Tidak ada koneksi WhatsApp default. Buat koneksi terlebih dahulu.',
                422,
                'connection_not_ready',
                nextAction: 'retry_setup',
            );
        }

        if ($connection->client_application_id !== $application->getKey()) {
            throw new ConnectionException('Koneksi tidak dimiliki aplikasi ini.', 403, 'authentication_failed');
        }

        $connection = $this->statusResolver->refresh($connection);

        if (! $connection->connectionStatus()->canSend()) {
            throw new ConnectionException(
                (string) ($connection->status_detail ?: 'Koneksi WhatsApp belum siap mengirim pesan.'),
                422,
                'connection_not_ready',
                retryable: true,
                nextAction: $connection->next_action,
            );
        }

        $this->assertCapability($connection, (string) ($payload['message']['type'] ?? 'text'));

        $existing = GatewayMessage::query()
            ->where('client_application_id', $application->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw new ConnectionException('Kunci idempotency sudah dipakai untuk payload berbeda.', 409, 'idempotency_conflict');
            }

            return $existing;
        }

        try {
            $message = DB::transaction(function () use (
                $application,
                $connection,
                $payload,
                $idempotencyKey,
                $payloadHash,
                $correlationId,
            ): GatewayMessage {
                return $this->createMessage($application, $connection, $payload, $idempotencyKey, $payloadHash, $correlationId);
            });
        } catch (UniqueConstraintViolationException) {
            $winner = GatewayMessage::query()
                ->where('client_application_id', $application->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($winner === null) {
                throw new ConnectionException('Pengiriman gagal disimpan.', 503, 'delivery_failed', true);
            }

            if (! hash_equals((string) $winner->payload_hash, $payloadHash)) {
                throw new ConnectionException('Kunci idempotency sudah dipakai untuk payload berbeda.', 409, 'idempotency_conflict');
            }

            return $winner;
        }

        if ((string) $message->mode === 'async') {
            if (! $this->enqueuer->enqueue($message, 'connection')) {
                $message->refresh();
            }

            return $message;
        }

        $dispatched = $this->dispatcher->dispatch($message);

        if ((string) $dispatched->status === 'provider_accepted') {
            $connection->forceFill(['last_successful_send_at' => now()])->save();
        }

        return $dispatched->fresh() ?? $dispatched;
    }

    public function resolveDefaultConnection(ClientApplication $application): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();
    }

    public function resolveConnection(ClientApplication $application, string $connectionId): WhatsAppConnection
    {
        $connection = WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('uuid', $connectionId)
            ->first();

        if ($connection === null) {
            throw new ConnectionException('Koneksi WhatsApp tidak ditemukan.', 404, 'connection_not_ready');
        }

        return $connection;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function createMessage(
        ClientApplication $application,
        WhatsAppConnection $connection,
        array $payload,
        string $idempotencyKey,
        string $payloadHash,
        string $correlationId,
    ): GatewayMessage {
        $recipient = $this->normalizeRecipient((string) data_get($payload, 'recipient.value', ''));
        $mode = (string) ($payload['mode'] ?? 'sync');
        $now = now();
        $provider = $connection->providerAccount;

        $message = new GatewayMessage;
        $attachment = $this->resolveAttachment($payload, $application);

        if ($attachment?->attachmentId !== null) {
            app(AttachmentService::class)->markReferenced($attachment->attachmentId);
        }

        $base = [
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->getKey(),
            'whatsapp_connection_id' => $connection->id,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'correlation_id' => $correlationId,
            'client_reference' => $payload['client_reference'] ?? null,
            'recipient' => $recipient,
            'recipient_hash' => hash_hmac('sha256', $recipient, (string) config('app.key')),
            'recipient_last4' => substr($recipient, -4),
            'body' => (string) data_get($payload, 'message.text', ''),
            'message_type' => (string) data_get($payload, 'message.type', 'text'),
            'attachment' => $attachment?->toArray(),
            'purpose' => (string) ($payload['purpose'] ?? 'notification'),
            'mode' => $mode,
            'origin' => 'connection',
            'priority' => 10,
            'status' => $mode === 'async' ? 'queued' : 'processing',
            'metadata' => array_merge(
                is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
                ['whatsapp_connection_id' => (string) $connection->uuid],
            ),
            'expires_at' => is_string($payload['expires_at'] ?? null)
                ? CarbonImmutable::parse($payload['expires_at'])->setTimezone(config('app.timezone'))
                : null,
            'queued_at' => $mode === 'async' ? $now : null,
            'processing_at' => $mode === 'sync' ? $now : null,
        ];

        if ($connection->isManagedNumber()) {
            $message->forceFill(array_merge($base, [
                'routing_policy_id' => $connection->routing_policy_id,
                'pinned_provider_account_id' => $provider?->id,
                'route_key' => (string) ($connection->session_id ?? 'default'),
            ]));
        } else {
            $message->forceFill(array_merge($base, [
                'routing_policy_id' => $connection->routing_policy_id,
                'route_key' => $connection->routingPolicy?->key ?? 'default',
            ]));
        }

        $message->save();

        $event = new MessageEvent;
        $event->forceFill([
            'gateway_message_id' => $message->getKey(),
            'type' => $mode === 'async' ? 'queued' : 'processing',
            'source' => 'connection',
            'data' => ['connection_id' => (string) $connection->uuid],
            'occurred_at' => $now,
        ]);
        $event->save();

        return $message;
    }

    private function normalizeRecipient(string $value): string
    {
        try {
            return $this->phones->normalize($value);
        } catch (InvalidArgumentException) {
            throw new ConnectionException('Nomor penerima tidak valid.', 422, 'recipient_invalid');
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveAttachment(array $payload, ClientApplication $application): ?OutboundAttachment
    {
        $attachmentId = data_get($payload, 'message.attachment.id');

        if (! is_string($attachmentId) || $attachmentId === '') {
            return null;
        }

        return OutboundAttachment::fromArray([
            'kind' => (string) data_get($payload, 'message.type'),
            'id' => $attachmentId,
            'filename' => data_get($payload, 'message.attachment.filename'),
            'mime_type' => data_get($payload, 'message.attachment.mime_type'),
        ]);
    }

    private function assertCapability(WhatsAppConnection $connection, string $messageType): void
    {
        $capability = match ($messageType) {
            'text' => ConnectionCapability::SendText,
            'image' => ConnectionCapability::SendImage,
            'document' => ConnectionCapability::SendDocument,
            'video' => ConnectionCapability::SendVideo,
            'audio' => ConnectionCapability::SendAudio,
            default => null,
        };

        if ($capability === null) {
            throw new ConnectionException('Tipe pesan tidak dikenali.', 422, 'capability_not_supported');
        }

        if (! $this->capabilities->supports($connection, $capability)) {
            throw new ConnectionException('Koneksi tidak mendukung tipe pesan ini.', 422, 'capability_not_supported');
        }
    }
}
