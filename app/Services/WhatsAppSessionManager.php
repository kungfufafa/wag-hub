<?php

namespace App\Services;

use App\Domain\WhatsApp\SessionStatus;
use App\Infrastructure\WhatsApp\InboxPayload;
use App\Infrastructure\WhatsApp\WahaSessionClient;
use App\Models\ProviderAccount;
use InvalidArgumentException;

/**
 * Owns the pairing lifecycle for self-hosted WhatsApp engines (WAHA/Baileys
 * NOWEB): connect, disconnect, restart, refresh status, and fetch the QR. It
 * also reconciles state pushed by the engine through the inbound webhook.
 */
final readonly class WhatsAppSessionManager
{
    public function __construct(
        private WahaSessionClient $client,
    ) {}

    public function connect(ProviderAccount $account, ?string $pairingPhone = null): SessionStatus
    {
        $this->assertSupported($account);

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

        $meta = is_array($account->session_meta) ? $account->session_meta : [];
        $stored = is_string($meta['pairing_code'] ?? null) ? trim((string) $meta['pairing_code']) : '';

        if ($stored !== '') {
            return $stored;
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

        return $this->persist($account, $this->client->logout($account));
    }

    public function restart(ProviderAccount $account): SessionStatus
    {
        $this->assertSupported($account);

        return $this->persist($account, $this->client->restart($account));
    }

    public function refresh(ProviderAccount $account): SessionStatus
    {
        $this->assertSupported($account);

        return $this->persist($account, $this->client->status($account));
    }

    public function qrDataUri(ProviderAccount $account): ?string
    {
        $this->assertSupported($account);

        return $this->client->qrDataUri($account);
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
