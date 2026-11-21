<?php

namespace Tests\Unit\Domain\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Services\Connections\ConnectionStatusProjector;
use Tests\TestCase;

class ConnectionStatusProjectorTest extends TestCase
{
    public function test_managed_numbers_map_engine_sessions_to_user_facing_states(): void
    {
        $projector = new ConnectionStatusProjector;

        $this->assertSame(ConnectionStatus::Connecting, $projector->projectEngineSession($this->snapshot('SCAN_QR_CODE')));
        $this->assertSame(ConnectionStatus::Connecting, $projector->projectEngineSession($this->snapshot('STARTING')));
        $this->assertSame(ConnectionStatus::Connecting, $projector->projectEngineSession($this->snapshot('STOPPED', ['pending_operation' => ['action' => 'start']])));
        $this->assertSame(ConnectionStatus::Ready, $projector->projectEngineSession($this->snapshot('WORKING')));
        $this->assertSame(ConnectionStatus::Degraded, $projector->projectEngineSession($this->snapshot('WORKING', ['stale' => true])));
        $this->assertSame(ConnectionStatus::Degraded, $projector->projectEngineSession($this->snapshot('WORKING', ['pending_operation' => ['action' => 'start']])));
        $this->assertSame(ConnectionStatus::Disconnected, $projector->projectEngineSession($this->snapshot('STOPPED', ['desired_state' => 'STOPPED'])));
        $this->assertSame(ConnectionStatus::Disconnected, $projector->projectEngineSession($this->snapshot('WORKING', ['deleted' => true])));
        $this->assertSame(ConnectionStatus::Error, $projector->projectEngineSession($this->snapshot('FAILED')));
        $this->assertSame(ConnectionStatus::SetupRequired, $projector->projectEngineSession($this->snapshot('STOPPED', [
            'stale' => true, 'last_error' => 'engine_unreachable', 'pending_operation' => ['action' => 'start'],
        ])));
        $this->assertSame(ConnectionStatus::SetupRequired, $projector->projectEngineSession(null));
    }

    public function test_provider_routes_use_health_and_circuit_state(): void
    {
        $projector = new ConnectionStatusProjector;

        $this->assertSame(ConnectionStatus::SetupRequired, $projector->projectRoute(null, false, false));
        $this->assertSame(ConnectionStatus::SetupRequired, $projector->projectRoute('unknown', false, true));
        $this->assertSame(ConnectionStatus::Ready, $projector->projectRoute('healthy', false, true));
        $this->assertSame(ConnectionStatus::Degraded, $projector->projectRoute('degraded', false, true));
        $this->assertSame(ConnectionStatus::Degraded, $projector->projectRoute('healthy', true, true));
        $this->assertSame(ConnectionStatus::Error, $projector->projectRoute('unavailable', false, true));
        $this->assertSame(ConnectionStatus::Disconnected, $projector->projectRoute('unknown', false, false));
    }

    public function test_each_non_ready_state_has_a_recommended_action(): void
    {
        $projector = new ConnectionStatusProjector;

        $this->assertSame('Scan QR', $projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::Connecting, $this->snapshot('SCAN_QR_CODE')));
        $this->assertSame('Enter pairing code', $projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::Connecting, $this->snapshot('SCAN_QR_CODE', ['auth_method' => 'pairing'])));
        $this->assertSame('Start WAG Hub runner', $projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::SetupRequired, $this->snapshot('STOPPED')));
        $this->assertSame('Reconnect', $projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::SetupRequired, null));
        $this->assertSame('Reconnect', $projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::Disconnected, $this->snapshot('STOPPED')));
        $this->assertSame('Fix credentials', $projector->recommendedAction(ConnectionType::ProviderRoute, ConnectionStatus::Error, null));
        $this->assertSame('Send a test message', $projector->recommendedAction(ConnectionType::ProviderRoute, ConnectionStatus::SetupRequired, null, true));
        $this->assertSame('Fix credentials', $projector->recommendedAction(ConnectionType::ProviderRoute, ConnectionStatus::SetupRequired, null, false));
        $this->assertSame('Provider temporarily unavailable', $projector->recommendedAction(ConnectionType::ProviderRoute, ConnectionStatus::Degraded, null));
        $this->assertNull($projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::Ready, $this->snapshot('WORKING')));
    }

    /**
     * A SessionService::snapshot() shape for a running, freshly observed session.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function snapshot(string $status, array $overrides = []): array
    {
        return [
            'status' => $status, 'desired_state' => 'RUNNING', 'stale' => false, 'engine_available' => true,
            'pending_operation' => null, 'last_error' => null, 'deleted' => false, 'auth_method' => null,
            ...$overrides,
        ];
    }
}
