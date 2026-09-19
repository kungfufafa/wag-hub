<?php

namespace App\Services\Connection;

use App\Domain\WhatsApp\SessionStatus;
use App\Models\WhatsAppConnection;
use App\Services\WhatsAppSessionManager;

final class ConnectionSetupPresenter
{
    public function __construct(private readonly WhatsAppSessionManager $sessions) {}

    /**
     * @return array<string, mixed>
     */
    public function setupPayload(WhatsAppConnection $connection): array
    {
        $provider = $connection->providerAccount;

        if ($provider === null) {
            return [
                'mode' => $connection->provisioning_state['mode'] ?? 'qr',
                'qr' => null,
                'pairing_code' => null,
            ];
        }

        $mode = $provider->configuration['engine_mode'] ?? $provider->configuration['cesa_mode'] ?? 'qr';
        $status = $provider->sessionStatus();
        $meta = is_array($provider->session_meta) ? $provider->session_meta : [];

        $payload = [
            'mode' => $mode,
            'session_status' => $status->value,
            'qr' => null,
            'pairing_code' => null,
            'phone' => $this->digitsFromMeta($meta['phone'] ?? null),
        ];

        if ($status === SessionStatus::ScanQr) {
            $payload['qr'] = $this->sessions->qrDataUri($provider);
        }

        if ($status === SessionStatus::ScanQr && $mode === 'pairing') {
            $payload['pairing_code'] = $this->sessions->pairingCode($provider)
                ?? (isset($meta['pairing_code']) ? (string) $meta['pairing_code'] : null);
        }

        return $payload;
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
