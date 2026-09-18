<?php

namespace App\Services;

use App\Domain\WhatsApp\SessionStatus;
use App\Exceptions\CesaEngineException;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\RoutingStep;
use App\Support\PayloadHasher;
use App\Support\PhoneNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * CESA Rekrutmen WhatsApp engine facade.
 *
 * cesa-web's WhatsAppEngineClient talks to a local Baileys process. This
 * service implements that HTTP contract on the Hub and drives the self-hosted
 * WAHA/Baileys engine already owned by this repository.
 */
final readonly class CesaEngineService
{
    public function __construct(
        private WhatsAppSessionManager $sessions,
        private GatewayMessageDispatcher $dispatcher,
        private PhoneNormalizer $phones,
        private PayloadHasher $hasher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function health(): array
    {
        $host = $this->hostProvider();

        return [
            'ok' => true,
            'engine' => 'wag-hub',
            'waha_ready' => $host !== null,
            'sessions' => ProviderAccount::query()
                ->where('driver', 'waha')
                ->where('slug', 'like', '%-sess-%')
                ->count(),
            'connected' => ProviderAccount::query()
                ->where('driver', 'waha')
                ->where('session_status', SessionStatus::Working->value)
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function startSession(ClientApplication $application, string $sessionId, string $mode, ?string $phone = null): array
    {
        $sessionId = $this->assertSessionId($sessionId);

        if (! in_array($mode, ['qr', 'pairing'], true)) {
            throw new CesaEngineException('Pilih mode QR atau pairing WhatsApp.', 422, 'invalid_mode');
        }

        $pairingPhone = null;

        if ($mode === 'pairing') {
            $pairingPhone = $this->normalizeOptionalPhone($phone);

            if ($pairingPhone === null) {
                throw new CesaEngineException('Nomor HP pairing WhatsApp tidak valid.', 422, 'invalid_phone');
            }
        }

        $account = $this->provisionSession($application, $sessionId, $mode);
        $this->sessions->connect($account, $pairingPhone);
        $account->refresh();

        return $this->publicSession($account, $sessionId, $mode, includeSecrets: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function session(ClientApplication $application, string $sessionId): array
    {
        $sessionId = $this->assertSessionId($sessionId);
        $account = $this->findSessionAccount($application, $sessionId);

        if ($account === null) {
            return $this->emptySession($sessionId);
        }

        $this->sessions->refresh($account);
        $account->refresh();

        return $this->publicSession($account, $sessionId, $this->storedMode($account), includeSecrets: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function logout(ClientApplication $application, string $sessionId, bool $logout = true): array
    {
        $sessionId = $this->assertSessionId($sessionId);
        $account = $this->findSessionAccount($application, $sessionId);

        if ($account === null) {
            return [
                'ok' => true,
                'status' => 'disconnected',
                'message' => 'Sesi WhatsApp tidak aktif.',
            ];
        }

        $confirmed = true;

        if ($logout) {
            try {
                $this->sessions->disconnect($account);
            } catch (Throwable) {
                $confirmed = false;
            }
        }

        $account->forceFill(['is_active' => false])->save();

        return [
            'ok' => true,
            'status' => 'disconnected',
            'logout_confirmed' => $confirmed,
            'message' => $confirmed
                ? 'Nomor WhatsApp berhasil diputuskan.'
                : 'Sesi lokal sudah diputuskan, tetapi logout dari WhatsApp belum terkonfirmasi. Hapus perangkat CESA dari menu Perangkat tertaut di HP bila masih tercantum.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(ClientApplication $application, string $sessionId, string $phone, string $text, string $key): array
    {
        $sessionId = $this->assertSessionId($sessionId);
        $key = $this->assertMessageKey($key);
        $account = $this->findSessionAccount($application, $sessionId);

        if ($account === null || ! $account->sessionStatus()->isConnected()) {
            return [
                'ok' => false,
                'status' => 'failed',
                'retryable' => true,
                'error_code' => 'not_connected',
                'message' => 'Nomor WhatsApp belum terhubung. Scan QR atau minta kode pairing baru.',
            ];
        }

        try {
            $recipient = $this->phones->normalize($phone);
        } catch (InvalidArgumentException) {
            throw new CesaEngineException('Nomor tujuan WhatsApp tidak valid.', 422, 'invalid_phone');
        }

        if (trim($text) === '' || strlen($text) > 10000) {
            throw new CesaEngineException('Isi pesan WhatsApp tidak valid.', 422, 'invalid_text');
        }

        $idempotencyKey = $this->hubIdempotencyKey($sessionId, $key);
        $payloadHash = $this->hasher->hash([
            'session' => $sessionId,
            'phone' => $recipient,
            'text' => $text,
        ], (string) config('app.key'));

        $existing = GatewayMessage::query()
            ->where('client_application_id', $application->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if (! hash_equals((string) $existing->payload_hash, $payloadHash)) {
                throw new CesaEngineException(
                    'Kunci pengiriman sudah digunakan untuk pesan berbeda.',
                    409,
                    'idempotency_conflict',
                );
            }

            return $this->sendResult($existing);
        }

        try {
            $message = DB::transaction(function () use (
                $application,
                $account,
                $sessionId,
                $recipient,
                $text,
                $idempotencyKey,
                $payloadHash,
            ): GatewayMessage {
                $now = now();
                $message = new GatewayMessage;
                $message->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'client_application_id' => $application->getKey(),
                    'routing_policy_id' => $this->sessionRouteId($application, $sessionId, $account),
                    'pinned_provider_account_id' => $account->id,
                    'idempotency_key' => $idempotencyKey,
                    'payload_hash' => $payloadHash,
                    'correlation_id' => (string) Str::uuid(),
                    'client_reference' => $sessionId,
                    'recipient' => $recipient,
                    'recipient_hash' => hash_hmac('sha256', $recipient, (string) config('app.key')),
                    'recipient_last4' => substr($recipient, -4),
                    'body' => $text,
                    'message_type' => 'text',
                    'purpose' => 'notification',
                    'route_key' => $sessionId,
                    'mode' => 'sync',
                    'origin' => 'engine',
                    'priority' => 10,
                    'status' => 'processing',
                    'metadata' => ['cesa_session_id' => $sessionId],
                    'processing_at' => $now,
                ]);
                $message->save();

                $event = new MessageEvent;
                $event->forceFill([
                    'gateway_message_id' => $message->getKey(),
                    'type' => 'processing',
                    'source' => 'engine',
                    'data' => null,
                    'occurred_at' => $now,
                ]);
                $event->save();

                return $message;
            });
        } catch (UniqueConstraintViolationException) {
            $winner = GatewayMessage::query()
                ->where('client_application_id', $application->getKey())
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($winner === null) {
                throw new CesaEngineException('Pengiriman WhatsApp gagal disimpan.', 503, 'journal_unavailable', true);
            }

            return $this->sendResult($winner);
        }

        $dispatched = $this->dispatcher->dispatch($message);

        return $this->sendResult($dispatched->fresh() ?? $dispatched);
    }

    /**
     * @return array<string, mixed>
     */
    public function messageStatus(ClientApplication $application, string $sessionId, string $key): array
    {
        $sessionId = $this->assertSessionId($sessionId);
        $key = $this->assertMessageKey($key);

        $message = GatewayMessage::query()
            ->where('client_application_id', $application->getKey())
            ->where('idempotency_key', $this->hubIdempotencyKey($sessionId, $key))
            ->first();

        if ($message === null) {
            throw new CesaEngineException('Pengiriman WhatsApp belum tercatat.', 404, 'message_not_found', true);
        }

        return $this->sendResult($message);
    }

    /**
     * @return array<string, mixed>
     */
    private function sendResult(GatewayMessage $message): array
    {
        $status = (string) $message->status;

        return match ($status) {
            'provider_accepted' => [
                'ok' => true,
                'status' => 'sent',
                'id' => (string) ($message->provider_message_id ?: $message->uuid),
            ],
            'failed', 'expired', 'dead_letter' => [
                'ok' => false,
                'status' => 'failed',
                'retryable' => in_array($message->last_error_code, ['route_unavailable', 'providers_failed', 'engine_provider_unavailable'], true),
                'error_code' => (string) ($message->last_error_code ?: 'providers_failed'),
                'message' => 'Pengiriman WhatsApp gagal.',
                'id' => $message->provider_message_id,
            ],
            default => [
                'ok' => false,
                'status' => 'unknown',
                'retryable' => false,
                'error_code' => 'delivery_unknown',
                'message' => 'Hasil pengiriman WhatsApp belum dapat dipastikan. Pesan tidak dikirim ulang otomatis.',
                'id' => $message->provider_message_id ?: $message->uuid,
            ],
        };
    }

    private function provisionSession(ClientApplication $application, string $sessionId, string $mode): ProviderAccount
    {
        $host = $this->hostProvider();

        if ($host === null) {
            throw new CesaEngineException(
                'Engine WAHA belum dikonfigurasi di Hub. Isi akun provider WAHA (base URL + API key), lalu coba lagi.',
                503,
                'engine_unavailable',
                true,
            );
        }

        $slug = $this->sessionSlug($application, $sessionId);
        $configuration = [
            'base_url' => $host->configuration['base_url'] ?? null,
            'api_key' => $host->configuration['api_key'] ?? null,
            'session' => $sessionId,
            'owned_by_application_id' => $application->getKey(),
            'cesa_session_id' => $sessionId,
            'cesa_mode' => $mode,
        ];

        $account = ProviderAccount::query()->where('slug', $slug)->first();

        if ($account === null) {
            $account = new ProviderAccount;
            $account->forceFill([
                'uuid' => (string) Str::uuid(),
                'name' => $application->name.' '.$sessionId,
                'slug' => $slug,
                'driver' => 'waha',
                'configuration' => $configuration,
                'is_active' => true,
                'health_status' => 'unknown',
                'session_status' => SessionStatus::Unknown->value,
                'timeout_seconds' => $host->timeout_seconds ?: 15,
            ]);
            $account->save();
        } else {
            $account->forceFill([
                'configuration' => array_merge($account->configuration ?? [], $configuration),
                'is_active' => true,
            ])->save();
        }

        $this->sessionRouteId($application, $sessionId, $account);

        return $account->fresh() ?? $account;
    }

    private function sessionRouteId(ClientApplication $application, string $sessionId, ProviderAccount $account): int
    {
        $policy = RoutingPolicy::query()
            ->where('client_application_id', $application->getKey())
            ->where('operation', 'message')
            ->where('key', $sessionId)
            ->whereNull('purpose')
            ->first();

        if ($policy === null) {
            $policy = new RoutingPolicy;
            $policy->forceFill([
                'client_application_id' => $application->getKey(),
                'operation' => 'message',
                'key' => $sessionId,
                'purpose' => null,
                'name' => $application->name.' '.$sessionId,
                'is_default' => false,
                'is_active' => true,
            ]);
            $policy->save();
        }

        if (! $policy->is_active) {
            $policy->forceFill(['is_active' => true])->save();
        }

        RoutingStep::query()->updateOrCreate(
            [
                'routing_policy_id' => $policy->id,
                'provider_account_id' => $account->id,
            ],
            [
                'position' => 1,
                'is_active' => true,
            ],
        );

        return (int) $policy->id;
    }

    private function findSessionAccount(ClientApplication $application, string $sessionId): ?ProviderAccount
    {
        return ProviderAccount::query()
            ->where('slug', $this->sessionSlug($application, $sessionId))
            ->where('driver', 'waha')
            ->first();
    }

    private function hostProvider(): ?ProviderAccount
    {
        $slug = (string) config('gateway.cesa_engine.host_provider_slug', 'waha-primary');

        $account = ProviderAccount::query()
            ->where('slug', $slug)
            ->where('driver', 'waha')
            ->first();

        if ($account === null) {
            $account = ProviderAccount::query()
                ->where('driver', 'waha')
                ->where('slug', 'not like', '%-sess-%')
                ->orderBy('id')
                ->first();
        }

        $baseUrl = is_string($account?->configuration['base_url'] ?? null)
            ? trim((string) $account->configuration['base_url'])
            : '';

        if ($account === null || $baseUrl === '') {
            return null;
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicSession(ProviderAccount $account, string $sessionId, string $mode, bool $includeSecrets): array
    {
        $hubStatus = $account->sessionStatus();
        $status = $this->cesaStatus($hubStatus, $mode);
        $meta = is_array($account->session_meta) ? $account->session_meta : [];
        $phone = $this->digitsFromMeta($meta['phone'] ?? null);
        $qr = null;
        $pairingCode = null;

        if ($includeSecrets && $status === 'qr') {
            $qr = $this->sessions->qrDataUri($account);
        }

        if ($includeSecrets && $status === 'pairing') {
            $pairingCode = $this->sessions->pairingCode($account) ?? (isset($meta['pairing_code']) ? (string) $meta['pairing_code'] : null);
        }

        return [
            'ok' => true,
            'id' => $sessionId,
            'status' => $status,
            'mode' => $mode,
            'qr' => $status === 'qr' ? $qr : null,
            'pairing_code' => $status === 'pairing' ? $pairingCode : null,
            'phone' => $phone,
            'error' => $hubStatus === SessionStatus::Failed ? 'Sesi WhatsApp gagal terhubung.' : null,
            'reconnect_attempt' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySession(string $sessionId): array
    {
        return [
            'ok' => true,
            'id' => $sessionId,
            'status' => 'disconnected',
            'mode' => null,
            'qr' => null,
            'pairing_code' => null,
            'phone' => null,
            'error' => null,
            'reconnect_attempt' => 0,
        ];
    }

    private function cesaStatus(SessionStatus $status, string $mode): string
    {
        return match ($status) {
            SessionStatus::Working => 'connected',
            SessionStatus::ScanQr => $mode === 'pairing' ? 'pairing' : 'qr',
            SessionStatus::Starting => 'connecting',
            SessionStatus::Failed, SessionStatus::Stopped => 'disconnected',
            SessionStatus::Unknown => 'unknown',
        };
    }

    private function storedMode(ProviderAccount $account): string
    {
        $mode = $account->configuration['cesa_mode'] ?? 'qr';

        return $mode === 'pairing' ? 'pairing' : 'qr';
    }

    private function sessionSlug(ClientApplication $application, string $sessionId): string
    {
        $slug = $application->slug.'-sess-'.$sessionId;

        return strlen($slug) <= 80 ? $slug : substr($slug, 0, 80);
    }

    private function hubIdempotencyKey(string $sessionId, string $key): string
    {
        $value = 'engine:'.$sessionId.':'.$key;

        if (strlen($value) > 160) {
            throw new CesaEngineException('Kunci pengiriman WhatsApp tidak valid.', 422, 'invalid_idempotency_key');
        }

        return $value;
    }

    private function assertSessionId(string $id): string
    {
        if (preg_match('/\A[a-z][a-z0-9-]{1,46}\z/', $id) !== 1 || str_contains($id, '--') || str_ends_with($id, '-')) {
            throw new CesaEngineException('ID sesi WhatsApp tidak valid.', 422, 'invalid_session');
        }

        return $id;
    }

    private function assertMessageKey(string $key): string
    {
        if ($key === '' || strlen($key) > 120 || trim($key) !== $key || $key === '.' || $key === '..'
            || preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            throw new CesaEngineException('Kunci pengiriman WhatsApp tidak valid.', 422, 'invalid_idempotency_key');
        }

        return $key;
    }

    private function normalizeOptionalPhone(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        try {
            return $this->phones->normalize($phone);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function digitsFromMeta(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', explode('@', $value)[0]) ?? '';

        return $digits !== '' ? $digits : null;
    }
}
