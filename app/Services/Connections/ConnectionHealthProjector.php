<?php

namespace App\Services\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Models\GatewayMessage;
use App\Models\RoutingStep;
use App\Models\WhatsAppConnection;

final class ConnectionHealthProjector
{
    public function __construct(
        private ConnectionCapabilityCatalog $capabilities,
        private ConnectionStatusProjector $statuses,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(WhatsAppConnection $connection): array
    {
        $status = $connection->statusEnum();
        $recent = GatewayMessage::query()
            ->where('whatsapp_connection_id', $connection->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get(['status', 'provider_accepted_at', 'failed_at', 'created_at']);

        $considered = $recent->filter(
            fn (GatewayMessage $message): bool => in_array((string) $message->status, [
                'provider_accepted',
                'failed',
                'outcome_unknown',
                'expired',
                'dead_letter',
            ], true),
        );
        $failed = $considered->filter(
            fn (GatewayMessage $message): bool => in_array((string) $message->status, [
                'failed',
                'outcome_unknown',
                'expired',
                'dead_letter',
            ], true),
        );
        $lastSuccess = $recent->first(
            fn (GatewayMessage $message): bool => (string) $message->status === 'provider_accepted',
        );

        return [
            'operational_status' => $status->operational(),
            'status' => $status->value,
            'last_successful_send_at' => $lastSuccess?->provider_accepted_at?->toIso8601String(),
            'recent_failure_rate' => $considered->isEmpty()
                ? 0.0
                : round($failed->count() / $considered->count(), 2),
            'active_sender' => $this->activeSender($connection),
            'health' => $connection->providerAccount?->health_status,
            'capabilities' => $connection->capabilities ?? [],
            'problems' => array_values(array_filter([
                $connection->recommended_action,
                $connection->last_error_message,
            ])),
        ];
    }

    /**
     * Project live status in memory without writing the connection row.
     */
    public function hydrate(WhatsAppConnection $connection): WhatsAppConnection
    {
        $connection->loadMissing(['providerAccount', 'routingPolicy.steps.providerAccount']);

        $capabilities = $this->capabilitiesFor($connection);
        $status = $this->projectStatus($connection);
        $session = $connection->providerAccount?->sessionStatus();

        $connection->forceFill([
            'capabilities' => $capabilities,
            'status' => $status->value,
            'recommended_action' => $this->statuses->recommendedAction(
                $connection->typeEnum(),
                $status,
                $session,
                $connection->isConfigured(),
            ),
        ]);
        $connection->syncOriginal();

        return $connection;
    }

    /**
     * Persist projected status, capabilities, and recommended action from live internals.
     */
    public function refresh(WhatsAppConnection $connection): WhatsAppConnection
    {
        $connection = $this->hydrate($connection);
        $connection->forceFill([
            'capabilities' => $connection->capabilities,
            'status' => $connection->status,
            'recommended_action' => $connection->recommended_action,
        ])->save();

        return $connection->fresh() ?? $connection;
    }

    /**
     * @return list<string>
     */
    public function capabilitiesFor(WhatsAppConnection $connection): array
    {
        if ($connection->pinsSender()) {
            return $this->capabilities->forDriver((string) ($connection->providerAccount?->driver ?? 'wag_hub'));
        }

        $drivers = RoutingStep::query()
            ->where('routing_policy_id', $connection->routing_policy_id)
            ->where('is_active', true)
            ->get()
            ->map(fn (RoutingStep $step): ?string => $step->providerAccount?->driver)
            ->filter()
            ->values()
            ->all();

        if ($drivers === [] && $connection->providerAccount) {
            $drivers = [$connection->providerAccount->driver];
        }

        return $this->capabilities->union($drivers);
    }

    public function projectStatus(WhatsAppConnection $connection): ConnectionStatus
    {
        $account = $connection->providerAccount;
        $circuitOpen = $account?->circuit_open_until !== null
            && now()->lessThan($account->circuit_open_until);

        $sessionLinked = filled($account?->configuration['engine_session_id'] ?? null)
            || filled($account?->configuration['cesa_session_id'] ?? null);

        if ($connection->pinsSender() || $sessionLinked) {
            return $this->statuses->projectManaged(
                $account?->session_status ? $account->sessionStatus() : null,
                (string) ($account?->health_status ?? 'unknown'),
                (bool) $circuitOpen,
            );
        }

        $configured = $account !== null && $account->is_active && $connection->routing_policy_id !== null;

        return $this->statuses->projectRoute(
            $account?->health_status,
            (bool) $circuitOpen,
            $configured,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeSender(WhatsAppConnection $connection): ?array
    {
        $account = $connection->providerAccount;

        if ($account === null) {
            return null;
        }

        $phone = is_array($account->session_meta) ? ($account->session_meta['phone'] ?? null) : null;

        return [
            'name' => $account->name,
            'driver' => $account->driver,
            'phone' => is_string($phone) && $phone !== '' ? $phone : null,
        ];
    }
}
