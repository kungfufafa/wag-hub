<?php

namespace App\Services;

use App\Domain\WhatsApp\SessionStatus;
use App\Exceptions\WhatsAppEngineException;
use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\RoutingStep;
use App\Support\PayloadHasher;
use App\Support\PhoneNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * User-linked WhatsApp engine (QR/pairing), separate from Hub API fallback.
 *
 * A credential with engine:use can start a session for a department number
 * (HR, recruitment, customer service, …). Sends stay pinned to that session
 * and never use the developer-configured provider pool.
 */
class WhatsAppEngineService
{
    public function __construct(
        protected WhatsAppSessionManager $sessions,
        protected GatewayMessageDispatcher $dispatcher,
        protected PhoneNormalizer $phones,
        protected PayloadHasher $hasher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function health(ClientApplication $application): array
    {
        $host = $this->hostProvider();
        $accounts = $this->ownedSessions($application)->filter(fn (ProviderAccount $account): bool => $account->is_active);

        return [
            'ok' => $host !== null,
            'engine' => 'wag-hub',
            'waha_ready' => $host !== null,
            'readiness' => $host !== null ? 'configured' : 'unconfigured',
            'sessions' => $accounts->count(),
            'connected' => $accounts->filter(fn (ProviderAccount $account): bool => $account->sessionStatus()->isConnected())->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function startSession(ClientApplication $application, string $sessionId, string $mode, ?string $phone = null): array
    {
        $sessionId = $this->assertSessionId($sessionId);

        if (! in_array($mode, ['qr', 'pairing'], true)) {
            throw new WhatsAppEngineException('Pilih mode QR atau pairing WhatsApp.', 422, 'invalid_mode');
        }

        $pairingPhone = null;

        if ($mode === 'pairing') {
            $pairingPhone = $this->normalizeOptionalPhone($phone);

            if ($pairingPhone === null) {
                throw new WhatsAppEngineException('Nomor HP pairing WhatsApp tidak valid.', 422, 'invalid_phone');
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

        if ($account === null || ! $account->is_active) {
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

        $confirmed = false;

        if ($logout) {
            try {
                $status = $this->sessions->disconnect($account);
                $confirmed = $status === SessionStatus::Stopped
                    && ($account->session_meta['raw'] ?? null) !== 'unreachable';
            } catch (Throwable) {
                $confirmed = false;
            }
        }

        $account->forceFill([
            'is_active' => false,
            'session_status' => SessionStatus::Stopped->value,
            'session_meta' => null,
        ])->save();

        return [
            'ok' => true,
            'status' => 'disconnected',
            'logout_confirmed' => $confirmed,
            'message' => $confirmed
                ? 'Nomor WhatsApp berhasil diputuskan.'
                : 'Sesi lokal sudah diputuskan, tetapi logout dari WhatsApp belum terkonfirmasi. Hapus perangkat tertaut layanan ini dari menu Perangkat tertaut di HP bila masih tercantum.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(ClientApplication $application, string $sessionId, string $phone, string $text, string $key): array
    {
        $sessionId = $this->assertSessionId($sessionId);
        $key = $this->assertMessageKey($key);
        try {
            $recipient = $this->phones->normalize($phone);
        } catch (InvalidArgumentException) {
            throw new WhatsAppEngineException('Nomor tujuan WhatsApp tidak valid.', 422, 'invalid_phone');
        }

        if (trim($text) === '' || strlen($text) > 10000) {
            throw new WhatsAppEngineException('Isi pesan WhatsApp tidak valid.', 422, 'invalid_text');
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
            $this->assertSamePayload($existing, $payloadHash);

            return $this->sendResult($existing);
        }

        $account = $this->findSessionAccount($application, $sessionId);

        if ($account === null || ! $account->is_active || ! $account->sessionStatus()->isConnected()) {
            return [
                'ok' => false,
                'status' => 'failed',
                'retryable' => true,
                'error_code' => 'not_connected',
                'message' => 'Nomor WhatsApp belum terhubung. Scan QR atau minta kode pairing baru.',
            ];
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
                    'metadata' => [
                        'engine_session_id' => $sessionId,
                        'cesa_session_id' => $sessionId,
                    ],
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
                throw new WhatsAppEngineException('Pengiriman WhatsApp gagal disimpan.', 503, 'journal_unavailable', true);
            }

            $this->assertSamePayload($winner, $payloadHash);

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
            throw new WhatsAppEngineException('Pengiriman WhatsApp belum tercatat.', 404, 'message_not_found', true);
        }

        return $this->sendResult($message);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sendResult(GatewayMessage $message): array
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

    protected function provisionSession(ClientApplication $application, string $sessionId, string $mode): ProviderAccount
    {
        return DB::transaction(function () use ($application, $sessionId, $mode): ProviderAccount {
            // Serialize provisioning for one owner. The account slug remains stable
            // even when the app is renamed, and no caller-selected upstream is used.
            ClientApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();
            $account = $this->findSessionAccount($application, $sessionId);

            if ($account === null) {
                $host = $this->hostProvider();

                if ($host === null) {
                    throw new WhatsAppEngineException(
                        'Engine WAHA belum dikonfigurasi di Hub. Isi akun provider WAHA (base URL + API key), lalu coba lagi.',
                        503,
                        'engine_unavailable',
                        true,
                    );
                }

                $uuid = (string) Str::uuid();
                $account = new ProviderAccount;
                $account->forceFill([
                    'uuid' => $uuid,
                    'name' => $application->name.' '.$sessionId,
                    'slug' => $this->sessionSlug($application, $sessionId),
                    'driver' => 'waha',
                    'configuration' => [
                        'base_url' => $host->configuration['base_url'],
                        'api_key' => $host->configuration['api_key'] ?? null,
                        'session' => 'wgh-'.$uuid,
                        'engine_host_provider_id' => $host->getKey(),
                        'owned_by_application_id' => $application->getKey(),
                        'engine_session_id' => $sessionId,
                        'cesa_session_id' => $sessionId,
                        'engine_mode' => $mode,
                        'cesa_mode' => $mode,
                    ],
                    'is_active' => true,
                    'health_status' => 'unknown',
                    'session_status' => SessionStatus::Unknown->value,
                    'timeout_seconds' => $host->timeout_seconds ?: 15,
                ]);
                $account->save();
            } else {
                // Existing linked devices stay on their original engine and session.
                $account->forceFill([
                    'configuration' => array_merge($account->configuration ?? [], [
                        'engine_session_id' => $sessionId,
                        'cesa_session_id' => $sessionId,
                        'engine_mode' => $mode,
                        'cesa_mode' => $mode,
                    ]),
                    'is_active' => true,
                ])->save();
            }

            $this->sessionRouteId($application, $sessionId, $account);

            return $account->fresh() ?? $account;
        });
    }

    protected function sessionRouteId(ClientApplication $application, string $sessionId, ProviderAccount $account): int
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

    protected function findSessionAccount(ClientApplication $application, string $sessionId): ?ProviderAccount
    {
        $matches = $this->ownedSessions($application)->filter(function (ProviderAccount $account) use ($sessionId): bool {
            $config = $account->configuration ?? [];

            return ($config['engine_session_id'] ?? $config['cesa_session_id'] ?? null) === $sessionId;
        });

        if ($matches->count() > 1) {
            throw new WhatsAppEngineException('Identitas sesi WhatsApp tidak unik. Hubungi administrator Hub.', 409, 'engine_session_conflict');
        }

        $account = $matches->first();

        if ($account !== null) {
            $this->assertExclusiveUpstream($account);
        }

        return $account;
    }

    /**
     * Configuration is encrypted, so ownership must be checked after decryption.
     * Do not derive authorization from a mutable or truncated display slug.
     *
     * @return Collection<int, ProviderAccount>
     */
    protected function ownedSessions(ClientApplication $application): Collection
    {
        return ProviderAccount::query()->where('driver', 'waha')->get()
            ->filter(fn (ProviderAccount $account): bool => (string) ($account->configuration['owned_by_application_id'] ?? '') === (string) $application->getKey());
    }

    protected function assertExclusiveUpstream(ProviderAccount $account): void
    {
        $config = $account->configuration ?? [];
        $baseUrl = rtrim((string) ($config['base_url'] ?? ''), '/');
        $session = $config['session'] ?? null;

        $shared = ProviderAccount::query()->where('driver', 'waha')->whereKeyNot($account->getKey())->get()
            ->contains(function (ProviderAccount $other) use ($baseUrl, $session): bool {
                $otherConfig = $other->configuration ?? [];

                return $session !== null
                    && ($otherConfig['session'] ?? null) === $session
                    && rtrim((string) ($otherConfig['base_url'] ?? ''), '/') === $baseUrl;
            });

        if ($shared) {
            throw new WhatsAppEngineException(
                'Sesi WhatsApp lama dipakai oleh beberapa akun. Administrator Hub harus memisahkan dan menautkan ulang perangkat.',
                409,
                'engine_session_conflict',
            );
        }
    }

    protected function assertSamePayload(GatewayMessage $message, string $payloadHash): void
    {
        if (! hash_equals((string) $message->payload_hash, $payloadHash)) {
            throw new WhatsAppEngineException('Kunci pengiriman sudah digunakan untuk pesan berbeda.', 409, 'idempotency_conflict');
        }
    }

    protected function hostProvider(): ?ProviderAccount
    {
        $slug = (string) config('gateway.engine.host_provider_slug', 'waha-primary');

        $account = ProviderAccount::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where('driver', 'waha')
            ->first();

        if ($account === null) {
            $account = ProviderAccount::query()
                ->where('driver', 'waha')
                ->where('is_active', true)
                ->orderBy('id')
                ->get()
                ->first(fn (ProviderAccount $provider): bool => ! $provider->isUserLinkedSession());
        }

        $baseUrl = is_string($account?->configuration['base_url'] ?? null)
            ? trim((string) $account->configuration['base_url'])
            : '';

        if ($account === null || $account->isUserLinkedSession() || $baseUrl === '') {
            return null;
        }

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    protected function publicSession(ProviderAccount $account, string $sessionId, string $mode, bool $includeSecrets): array
    {
        $hubStatus = $account->sessionStatus();
        $status = $this->normalizeStatus($hubStatus, $mode);
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
    protected function emptySession(string $sessionId): array
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

    protected function normalizeStatus(SessionStatus $status, string $mode): string
    {
        return match ($status) {
            SessionStatus::Working => 'connected',
            SessionStatus::ScanQr => $mode === 'pairing' ? 'pairing' : 'qr',
            SessionStatus::Starting => 'connecting',
            SessionStatus::Failed, SessionStatus::Stopped => 'disconnected',
            SessionStatus::Unknown => 'unknown',
        };
    }

    /**
     * @deprecated Use normalizeStatus instead. Retained for backward compatibility.
     */
    protected function cesaStatus(SessionStatus $status, string $mode): string
    {
        return $this->normalizeStatus($status, $mode);
    }

    protected function storedMode(ProviderAccount $account): string
    {
        $mode = $account->configuration['engine_mode'] ?? $account->configuration['cesa_mode'] ?? 'qr';

        return $mode === 'pairing' ? 'pairing' : 'qr';
    }

    protected function sessionSlug(ClientApplication $application, string $sessionId): string
    {
        return 'app-sess-'.hash('sha256', $application->uuid."\0".$sessionId);
    }

    protected function hubIdempotencyKey(string $sessionId, string $key): string
    {
        $value = 'engine:'.$sessionId.':'.$key;

        if (strlen($value) > 160) {
            throw new WhatsAppEngineException('Kunci pengiriman WhatsApp tidak valid.', 422, 'invalid_idempotency_key');
        }

        return $value;
    }

    protected function assertSessionId(string $id): string
    {
        if (preg_match('/\A[a-z][a-z0-9-]{1,46}\z/', $id) !== 1 || str_contains($id, '--') || str_ends_with($id, '-')) {
            throw new WhatsAppEngineException('ID sesi WhatsApp tidak valid.', 422, 'invalid_session');
        }

        return $id;
    }

    protected function assertMessageKey(string $key): string
    {
        if ($key === '' || strlen($key) > 120 || trim($key) !== $key || $key === '.' || $key === '..'
            || preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            throw new WhatsAppEngineException('Kunci pengiriman WhatsApp tidak valid.', 422, 'invalid_idempotency_key');
        }

        return $key;
    }

    protected function normalizeOptionalPhone(?string $phone): ?string
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

    protected function digitsFromMeta(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', explode('@', $value)[0]) ?? '';

        return $digits !== '' ? $digits : null;
    }
}
