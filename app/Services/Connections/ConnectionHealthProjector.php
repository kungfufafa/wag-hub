<?php

namespace App\Services\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Models\EngineMessage;
use App\Models\GatewayMessage;
use App\Models\RoutingStep;
use App\Models\WhatsAppConnection;
use App\Services\Engine\SessionService;

final class ConnectionHealthProjector
{
    private const FAILED_GATEWAY_STATUSES = ['failed', 'outcome_unknown', 'expired', 'dead_letter'];

    private const FAILED_ENGINE_STATUSES = ['failed', 'outcome_unknown', 'expired'];

    public function __construct(
        private ConnectionCapabilityCatalog $capabilities,
        private ConnectionStatusProjector $statuses,
        private SessionService $sessions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summarize(WhatsAppConnection $connection): array
    {
        $status = $connection->statusEnum();
        [$considered, $failed, $lastSuccessAt] = $connection->isManaged()
            ? $this->engineDeliveries($connection)
            : $this->gatewayDeliveries($connection);

        return [
            'operational_status' => $status->operational(),
            'status' => $status->value,
            'last_successful_send_at' => $lastSuccessAt,
            'recent_failure_rate' => $considered === 0 ? 0.0 : round($failed / $considered, 2),
            'active_sender' => $this->activeSender($connection),
            'health' => $this->healthLabel($connection),
            'capabilities' => $connection->capabilities ?? [],
            'problems' => array_values(array_filter([
                $connection->recommended_action,
                $connection->last_error_message,
            ])),
        ];
    }

    /**
     * The engine session snapshot behind a managed number, or null when none is linked.
     *
     * @return array<string, mixed>|null
     */
    public function sessionSnapshot(WhatsAppConnection $connection): ?array
    {
        if (! $connection->isManaged()) {
            return null;
        }

        $session = $connection->ownedEngineSession();

        return $session === null ? null : $this->sessions->snapshot($session);
    }

    /**
     * Project live status in memory without writing the connection row.
     */
    public function hydrate(WhatsAppConnection $connection): WhatsAppConnection
    {
        if ($connection->isManaged()) {
            $connection->loadMissing('engineSession.pendingOperation');
            $session = $this->sessionSnapshot($connection);
            $status = $this->statuses->projectEngineSession($session);

            if ($session !== null && $session['auth_method'] === null) {
                // Until the runner reports a challenge, the requested method decides QR vs pairing.
                $session['auth_method'] = $connection->setup_state['auth_method'] ?? null;
            }

            $action = $this->statuses->recommendedEngineAction($status, $session);
        } else {
            $connection->loadMissing(['providerAccount', 'routingPolicy.steps.providerAccount']);
            $status = $this->projectRouteStatus($connection);
            $action = $this->statuses->recommendedRouteAction($status, $connection->isConfigured());
        }

        $connection->forceFill([
            'capabilities' => $this->capabilitiesFor($connection),
            'status' => $status->value,
            'recommended_action' => $action,
        ]);
        $connection->syncOriginal();

        return $connection;
    }

    /**
     * Persist projected status, capabilities, and recommended action from live internals.
     */
    public function refresh(WhatsAppConnection $connection): WhatsAppConnection
    {
        $connection->unsetRelation('engineSession');
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
        if ($connection->isManaged()) {
            return $this->capabilities->forEngineSession();
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

    public function projectRouteStatus(WhatsAppConnection $connection): ConnectionStatus
    {
        $account = $connection->providerAccount;
        $circuitOpen = $account?->circuit_open_until !== null
            && now()->lessThan($account->circuit_open_until);
        $configured = $account !== null && $account->is_active && $connection->routing_policy_id !== null;

        return $this->statuses->projectRoute(
            $account?->health_status,
            (bool) $circuitOpen,
            $configured,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: string|null}
     */
    private function gatewayDeliveries(WhatsAppConnection $connection): array
    {
        $recent = GatewayMessage::query()
            ->where('whatsapp_connection_id', $connection->getKey())
            ->orderByDesc('id')
            ->limit(50)
            ->get(['status', 'provider_accepted_at']);
        $considered = $recent->filter(fn (GatewayMessage $message): bool => in_array(
            (string) $message->status,
            ['provider_accepted', ...self::FAILED_GATEWAY_STATUSES],
            true,
        ));
        $failed = $considered->filter(fn (GatewayMessage $message): bool => in_array(
            (string) $message->status,
            self::FAILED_GATEWAY_STATUSES,
            true,
        ));
        $lastSuccess = $recent->first(fn (GatewayMessage $message): bool => (string) $message->status === 'provider_accepted');

        return [$considered->count(), $failed->count(), $lastSuccess?->provider_accepted_at?->toIso8601String()];
    }

    /**
     * @return array{0: int, 1: int, 2: string|null}
     */
    private function engineDeliveries(WhatsAppConnection $connection): array
    {
        if ($connection->engine_session_id === null) {
            return [0, 0, null];
        }

        $recent = EngineMessage::query()
            ->where('client_application_id', $connection->client_application_id)
            ->where('engine_session_id', $connection->engine_session_id)
            ->orderByDesc('id')
            ->limit(50)
            ->get(['transport_status', 'updated_at']);
        $considered = $recent->filter(fn (EngineMessage $message): bool => in_array(
            $message->transport_status,
            ['accepted', ...self::FAILED_ENGINE_STATUSES],
            true,
        ));
        $failed = $considered->filter(fn (EngineMessage $message): bool => in_array(
            $message->transport_status,
            self::FAILED_ENGINE_STATUSES,
            true,
        ));
        $lastSuccess = $recent->first(fn (EngineMessage $message): bool => $message->transport_status === 'accepted');

        return [$considered->count(), $failed->count(), $lastSuccess?->updated_at?->toIso8601String()];
    }

    private function healthLabel(WhatsAppConnection $connection): ?string
    {
        if (! $connection->isManaged()) {
            return $connection->providerAccount?->health_status;
        }

        $session = $this->sessionSnapshot($connection);

        if ($session === null) {
            return 'unknown';
        }

        return $session['stale'] ? 'unavailable' : 'healthy';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeSender(WhatsAppConnection $connection): ?array
    {
        if ($connection->isManaged()) {
            $session = $connection->ownedEngineSession();

            return $session === null ? null : [
                'name' => $session->name,
                'driver' => 'wag_hub',
                'phone' => filled($session->phone_number) ? (string) $session->phone_number : null,
            ];
        }

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
