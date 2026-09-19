<?php

namespace App\Services\Connections;

use App\Models\WhatsAppConnection;
use App\Services\WhatsAppSessionManager;

final class ConnectionPresenter
{
    public function __construct(
        private ConnectionHealthProjector $health,
        private WhatsAppSessionManager $sessions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(WhatsAppConnection $connection, bool $includePairingSecrets = false): array
    {
        $connection = $this->health->hydrate($connection);
        $account = $connection->providerAccount;
        $application = $connection->clientApplication;
        $status = $connection->statusEnum();
        $health = $this->health->summarize($connection);
        $payload = [
            'id' => (string) $connection->uuid,
            'application' => (string) $application?->uuid,
            'name' => $connection->name,
            'type' => $connection->type,
            'status' => $status->value,
            'is_default' => (bool) $connection->is_default,
            'capabilities' => $connection->capabilities ?? [],
            'sender' => $health['active_sender'],
            'health' => $health,
            'recommended_action' => $connection->recommended_action,
        ];

        if ($includePairingSecrets && $account?->supportsSessions()) {
            $mode = $account->configuration['engine_mode'] ?? $account->configuration['cesa_mode'] ?? 'qr';
            $sessionStatus = $account->sessionStatus();

            if ($sessionStatus->needsQr()) {
                $payload['qr'] = $mode === 'pairing' ? null : $this->sessions->qrDataUri($account);
                $payload['pairing_code'] = $mode === 'pairing'
                    ? ($this->sessions->pairingCode($account) ?? ($account->session_meta['pairing_code'] ?? null))
                    : null;
            }
        }

        return $payload;
    }
}
