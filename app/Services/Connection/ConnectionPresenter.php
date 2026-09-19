<?php

namespace App\Services\Connection;

use App\Models\WhatsAppConnection;

final class ConnectionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(WhatsAppConnection $connection, bool $includeSetupSecrets = false): array
    {
        $data = [
            'id' => (string) $connection->uuid,
            'application' => $connection->clientApplication?->slug,
            'name' => (string) $connection->name,
            'type' => (string) $connection->type,
            'status' => (string) $connection->status,
            'is_default' => (bool) $connection->is_default,
            'capabilities' => array_values($connection->capabilities ?? []),
            'sender_identity' => $connection->sender_identity,
            'health' => $this->healthView($connection),
            'next_action' => $connection->next_action,
            'status_detail' => $connection->status_detail,
            'session_id' => $connection->session_id,
            'created_at' => $connection->created_at?->toIso8601String(),
            'updated_at' => $connection->updated_at?->toIso8601String(),
        ];

        if ($includeSetupSecrets && $connection->isManagedNumber() && $connection->providerAccount !== null) {
            $data['setup'] = app(ConnectionSetupPresenter::class)->setupPayload($connection);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function healthView(WhatsAppConnection $connection): array
    {
        $summary = is_array($connection->health_summary) ? $connection->health_summary : [];

        return [
            'operational' => $summary['operational'] ?? 'unknown',
            'failure_rate_24h' => $summary['failure_rate_24h'] ?? null,
            'last_successful_send_at' => $connection->last_successful_send_at?->toIso8601String()
                ?? ($summary['last_successful_send_at'] ?? null),
        ];
    }
}
