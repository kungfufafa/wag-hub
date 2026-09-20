<?php

namespace App\Services;

use App\Domain\WhatsApp\SessionStatus;
use App\Exceptions\WhatsAppEngineException;
use App\Infrastructure\WhatsApp\BaileysClient;
use App\Infrastructure\WhatsApp\InboxPayload;
use App\Infrastructure\WhatsApp\WahaSessionClient;
use App\Models\EngineSession;
use App\Models\ProviderAccount;
use App\Services\Engine\SessionService;
use InvalidArgumentException;

/**
 * Owns the pairing lifecycle for WAG Hub native and WAHA engines: connect, disconnect, restart, refresh status, and fetch the QR. It
 * also reconciles state pushed by the engine through the inbound webhook.
 */
final readonly class WhatsAppSessionManager
{
    public function __construct(
        private WahaSessionClient $client,
        private BaileysClient $native,
        private SessionService $engine,
    ) {}

    public function connect(ProviderAccount $account, ?string $pairingPhone = null): SessionStatus
    {
        $this->assertSupported($account);

        if ($session = $this->mappedSession($account)) {
            return $this->mappedAction($account, $session, 'start', [
                'mode' => $pairingPhone ? 'pairing' : 'qr', 'phone' => $pairingPhone,
            ]);
        }

        if ($account->driver === 'wag_hub') {
            return $this->persistNative($account, $this->native->start($account, $pairingPhone));
        }

        $status = $this->persist($account, $this->client->start($account));

        if ($pairingPhone !== null && $pairingPhone !== '') {
            $code = $this->client->requestPairingCode($account, $pairingPhone);
            $meta = is_array($account->session_meta) ? $account->session_meta : [];
            $meta['pairing_phone'] = $pairingPhone;

            if ($code !== null) {
                $meta['pairing_code'] = $code;
            }

            $account->forceFill(['session_meta' => $meta])->save();
        }

        return $status;
    }

    public function pairingCode(ProviderAccount $account): ?string
    {
        $this->assertSupported($account);

        if ($session = $this->mappedSession($account)) {
            return $this->mappedChallenge($session)['pairing_code'] ?? null;
        }

        $meta = is_array($account->session_meta) ? $account->session_meta : [];
        $stored = is_string($meta['pairing_code'] ?? null) ? trim((string) $meta['pairing_code']) : '';

        if ($stored !== '') {
            return $stored;
        }

        if ($account->driver === 'wag_hub') {
            return null;
        }

        $phone = is_string($meta['pairing_phone'] ?? null) ? trim((string) $meta['pairing_phone']) : '';

        if ($phone === '') {
            return null;
        }

        $code = $this->client->requestPairingCode($account, $phone);

        if ($code === null) {
            return null;
        }

        $meta['pairing_code'] = $code;
        $account->forceFill(['session_meta' => $meta])->save();

        return $code;
    }

    public function disconnect(ProviderAccount $account): SessionStatus
    {
        $this->assertSupported($account);

        if ($session = $this->mappedSession($account)) {
            return $this->mappedAction($account, $session, 'logout');
        }

        if ($account->driver === 'wag_hub') {
            return $this->persistNative($account, $this->native->logout($account));
        }

        return $this->persist($account, $this->client->logout($account));
    }

    public function restart(ProviderAccount $account): SessionStatus
    {
        $this->assertSupported($account);

        if ($session = $this->mappedSession($account)) {
            // Do not resume if teardown is still pending or failed.
            $this->mappedAction($account, $session, 'stop');

            return $this->mappedAction($account, $session->fresh(), 'start', ['mode' => 'qr']);
        }

        if ($account->driver === 'wag_hub') {
            $this->native->logout($account, false);

            return $this->persistNative($account, $this->native->start($account));
        }

        return $this->persist($account, $this->client->restart($account));
    }

    public function refresh(ProviderAccount $account): SessionStatus
    {
        $this->assertSupported($account);

        if ($session = $this->mappedSession($account)) {
            return $this->persistMapped($account, $session->trashed() ? $session : $this->engine->refresh($session));
        }

        if ($account->driver === 'wag_hub') {
            try {
                return $this->persistNative($account, $this->native->status($account));
            } catch (WhatsAppEngineException) {
                return $this->persist($account, null);
            }
        }

        return $this->persist($account, $this->client->status($account));
    }

    public function qrDataUri(ProviderAccount $account): ?string
    {
        $this->assertSupported($account);

        if ($session = $this->mappedSession($account)) {
            return $this->mappedChallenge($session)['qr'] ?? null;
        }

        if ($account->driver === 'wag_hub') {
            try {
                $snapshot = $this->native->status($account);

                return ($snapshot['status'] ?? null) === 'qr' ? ($snapshot['qr'] ?? null) : null;
            } catch (WhatsAppEngineException) {
                return null;
            }
        }

        return $this->client->qrDataUri($account);
    }

    /** Remove the legacy record only after its authoritative session is torn down. */
    public function deleteProvider(ProviderAccount $account): bool
    {
        if (($session = $this->mappedSession($account)) && ! $session->trashed()) {
            $this->mappedAction($account, $session, 'delete');
        }

        return (bool) $account->delete();
    }

    private function mappedSession(ProviderAccount $account): ?EngineSession
    {
        if ($account->driver !== 'wag_hub') {
            return null;
        }

        // The immutable runner identity survives edited legacy configuration and tombstones.
        return EngineSession::withTrashed()->where('runner_id', $this->native->sessionId($account))->first();
    }

    private function mappedAction(ProviderAccount $account, EngineSession $session, string $action, array $payload = []): SessionStatus
    {
        if ($session->trashed()) {
            throw new WhatsAppEngineException('Sesi ini sudah dihapus. Tautkan akun baru.', 410, 'session_removed');
        }

        $operation = $this->engine->command($session, $action, $payload);
        $this->engine->execute($operation);
        $operation->refresh();
        $status = $this->persistMapped($account, $session->fresh());

        if ($operation->status !== 'completed') {
            throw new WhatsAppEngineException(
                $operation->status === 'failed'
                    ? 'Operasi sesi gagal. Segarkan status sebelum mencoba lagi.'
                    : 'Operasi tersimpan dan masih menunggu konfirmasi engine. Status akan diperbarui otomatis.',
                503,
                $operation->status === 'failed' ? ($operation->last_error ?? 'operation_failed') : 'operation_pending',
                $operation->status !== 'failed',
            );
        }

        return $status;
    }

    private function persistMapped(ProviderAccount $account, EngineSession $session): SessionStatus
    {
        $snapshot = $this->engine->snapshot($session);
        $status = $snapshot['stale'] || $snapshot['pending_operation']
            ? SessionStatus::Unknown
            : SessionStatus::fromWaha($snapshot['status']);
        $this->write($account, $status, [
            'engine' => 'wag_hub', 'hub_session_id' => $session->uuid,
            'raw' => $snapshot['status'], 'phone' => $snapshot['phone_number'],
            'stale' => $snapshot['stale'], 'engine_available' => $snapshot['engine_available'],
            'pending_operation' => $snapshot['pending_operation'], 'deleted' => $snapshot['deleted'],
        ]);

        return $status;
    }

    private function mappedChallenge(EngineSession $session): array
    {
        if ($session->trashed() || $session->desired_state !== 'RUNNING' || $session->pending_operation_id) {
            return [];
        }

        try {
            $challenge = $this->engine->challenge($session);
            $current = $session->fresh();
            if ($current->trashed() || $current->desired_state !== 'RUNNING' || $current->pending_operation_id
                || $current->generation !== $session->generation
                || (int) ($challenge['generation'] ?? -1) !== $current->generation) {
                return [];
            }

            return $challenge;
        } catch (WhatsAppEngineException) {
            return [];
        }
    }

    private function persistNative(ProviderAccount $account, array $snapshot): SessionStatus
    {
        $raw = $snapshot['status'] ?? 'unknown';
        $status = match ($raw) {
            'connected' => SessionStatus::Working,
            'qr', 'pairing' => SessionStatus::ScanQr,
            'connecting' => SessionStatus::Starting,
            'disconnected' => SessionStatus::Stopped,
            default => SessionStatus::Unknown,
        };
        $meta = array_filter([
            'raw' => $raw,
            'engine' => 'wag_hub',
            'phone' => $snapshot['phone'] ?? null,
            'pairing_code' => $raw === 'pairing' ? ($snapshot['pairing_code'] ?? null) : null,
            'logout_confirmed' => $snapshot['logout_confirmed'] ?? null,
        ], fn (mixed $value): bool => $value !== null);
        $this->write($account, $status, $meta);

        return $status;
    }

    /**
     * Apply a session-status update pushed by the engine webhook. Returns true
     * when the payload was a recognised session event (so the caller can skip
     * message parsing for it).
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(ProviderAccount $account, array $payload): bool
    {
        if (! $account->supportsSessions()) {
            return false;
        }

        $event = strtolower((string) InboxPayload::string($payload['event'] ?? $payload['type'] ?? null));

        if ($event !== 'session.status' && $event !== 'session.upsert' && $event !== 'state.change') {
            return false;
        }

        if ($session = $this->mappedSession($account)) {
            // Legacy callbacks lack the generation fence; only project the hub's accepted observation.
            $this->persistMapped($account, $session);

            return true;
        }

        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $status = InboxPayload::string($inner['status'] ?? $inner['state'] ?? null);

        $this->write(
            $account,
            SessionStatus::fromWaha($status),
            $this->metaFrom($inner, $status),
        );

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $status
     */
    private function persist(ProviderAccount $account, ?array $status): SessionStatus
    {
        if ($status === null) {
            // Engine unreachable: don't clobber a previously known-good state,
            // just mark that we couldn't confirm it right now.
            $current = $account->sessionStatus();
            $this->write($account, $current === SessionStatus::Unknown ? SessionStatus::Failed : $current, [
                'raw' => 'unreachable',
            ] + (is_array($account->session_meta) ? $account->session_meta : []));

            return $account->sessionStatus();
        }

        $raw = InboxPayload::string($status['status'] ?? $status['state'] ?? null);
        $resolved = SessionStatus::fromWaha($raw);

        $this->write($account, $resolved, $this->metaFrom($status, $raw));

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    private function metaFrom(array $status, ?string $raw): array
    {
        $me = is_array($status['me'] ?? null) ? $status['me'] : [];

        return array_filter([
            'raw' => $raw,
            'phone' => InboxPayload::string($me['id'] ?? $status['pushName'] ?? null),
            'push_name' => InboxPayload::string($me['pushName'] ?? $me['name'] ?? null),
            'engine' => InboxPayload::string(data_get($status, 'engine.engine') ?? ($status['engine'] ?? null)),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function write(ProviderAccount $account, SessionStatus $status, array $meta): void
    {
        $account->forceFill([
            'session_status' => $status->value,
            'session_meta' => $meta === [] ? null : $meta,
            'session_synced_at' => now(),
        ])->save();
    }

    private function assertSupported(ProviderAccount $account): void
    {
        if (! $account->supportsSessions()) {
            throw new InvalidArgumentException('Akun ini bukan engine self-hosted yang mendukung pairing sesi.');
        }
    }
}
