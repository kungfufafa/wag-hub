<?php

namespace App\Services\Connections;

use App\Exceptions\WhatsAppEngineException;
use App\Models\EngineSession;
use App\Models\WhatsAppConnection;
use App\Services\Engine\SessionService;
use App\Services\WhatsAppSessionManager;

final class ConnectionPresenter
{
    public function __construct(
        private ConnectionHealthProjector $health,
        private WhatsAppSessionManager $providerSessions,
        private SessionService $sessions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(WhatsAppConnection $connection, bool $includePairingSecrets = false): array
    {
        $connection = $this->health->hydrate($connection);
        $application = $connection->clientApplication;
        $health = $this->health->summarize($connection);
        $payload = [
            'id' => (string) $connection->uuid,
            'application' => (string) $application?->uuid,
            'name' => $connection->name,
            'type' => $connection->type,
            'status' => $connection->statusEnum()->value,
            'is_default' => (bool) $connection->is_default,
            'capabilities' => $connection->capabilities ?? [],
            'sender' => $health['active_sender'],
            'health' => $health,
            'recommended_action' => $connection->recommended_action,
            'created_at' => $connection->created_at?->toISOString(),
            'updated_at' => $connection->updated_at?->toISOString(),
        ];

        if ($connection->isManaged()) {
            return $this->withSession($payload, $connection, $includePairingSecrets);
        }

        $account = $connection->providerAccount;

        // Shared, admin-managed sessions never hand their QR or pairing code to a tenant.
        if ($includePairingSecrets && $account?->supportsSessions() && $connection->ownsProviderAccount($account)) {
            $mode = $account->configuration['engine_mode'] ?? $account->configuration['cesa_mode'] ?? 'qr';

            if ($account->sessionStatus()->needsQr()) {
                $payload['qr'] = $mode === 'pairing' ? null : $this->providerSessions->qrDataUri($account);
                $payload['pairing_code'] = $mode === 'pairing'
                    ? ($this->providerSessions->pairingCode($account) ?? ($account->session_meta['pairing_code'] ?? null))
                    : null;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withSession(array $payload, WhatsAppConnection $connection, bool $includePairingSecrets): array
    {
        $session = $connection->ownedEngineSession();
        $snapshot = $session === null ? null : $this->sessions->snapshot($session);
        $operation = $snapshot['pending_operation'] ?? null;

        $payload['session'] = $snapshot === null ? null : [
            'id' => $snapshot['id'],
            'status' => $snapshot['status'],
            'desired_state' => $snapshot['desired_state'],
            'auth_method' => $snapshot['auth_method'],
            'phone_number' => $snapshot['phone_number'],
            'stale' => $snapshot['stale'],
            'engine_available' => $snapshot['engine_available'],
            'last_error' => $snapshot['last_error'],
            'deleted' => $snapshot['deleted'],
        ];
        // A pending command is still being carried out by the runner: callers poll and retry later.
        $payload['operation'] = $operation === null ? null : [...$operation, 'retryable' => true];

        if ($includePairingSecrets && $session !== null) {
            $challenge = $this->challenge($session);

            if ($challenge !== []) {
                $payload['qr'] = $challenge['qr'] ?? null;
                $payload['pairing_code'] = $challenge['pairing_code'] ?? null;
                $payload['challenge_expires_at'] = $challenge['expires_at'] ?? null;
            }
        }

        return $payload;
    }

    /**
     * The current QR or pairing challenge, only while the session waits for one and the runner
     * answered for the session's current generation. Runner outages simply yield no challenge.
     *
     * @return array<string, mixed>
     */
    private function challenge(EngineSession $session): array
    {
        if ($session->trashed() || $session->desired_state !== 'RUNNING' || $session->pending_operation_id !== null
            || $session->status !== 'SCAN_QR_CODE') {
            return [];
        }

        try {
            $challenge = $this->sessions->challenge($session);
        } catch (WhatsAppEngineException) {
            return [];
        }

        $current = $session->fresh();

        if ($current === null || $current->trashed() || $current->desired_state !== 'RUNNING'
            || $current->pending_operation_id !== null || $current->generation !== $session->generation
            || (isset($challenge['generation']) && (int) $challenge['generation'] !== $current->generation)) {
            return [];
        }

        return $challenge;
    }
}
