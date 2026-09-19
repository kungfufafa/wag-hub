<?php

namespace Tests\Unit\Domain\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Domain\WhatsApp\SessionStatus;
use App\Services\Connections\ConnectionStatusProjector;
use Tests\TestCase;

class ConnectionStatusProjectorTest extends TestCase
{
    public function test_managed_numbers_map_engine_sessions_to_user_facing_states(): void
    {
        $projector = new ConnectionStatusProjector;

        $this->assertSame(ConnectionStatus::Connecting, $projector->projectManaged(SessionStatus::ScanQr, 'healthy', false));
        $this->assertSame(ConnectionStatus::Connecting, $projector->projectManaged(SessionStatus::Starting, 'unknown', false));
        $this->assertSame(ConnectionStatus::Ready, $projector->projectManaged(SessionStatus::Working, 'healthy', false));
        $this->assertSame(ConnectionStatus::Degraded, $projector->projectManaged(SessionStatus::Working, 'degraded', true));
        $this->assertSame(ConnectionStatus::Disconnected, $projector->projectManaged(SessionStatus::Stopped, 'unknown', false));
        $this->assertSame(ConnectionStatus::Error, $projector->projectManaged(SessionStatus::Failed, 'unavailable', false));
        $this->assertSame(ConnectionStatus::SetupRequired, $projector->projectManaged(null, 'unknown', false));
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

        $this->assertSame('Scan QR', $projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::Connecting, SessionStatus::ScanQr));
        $this->assertSame('Start WAG Hub runner', $projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::SetupRequired, null));
        $this->assertSame('Reconnect', $projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::Disconnected, SessionStatus::Stopped));
        $this->assertSame('Fix credentials', $projector->recommendedAction(ConnectionType::ProviderRoute, ConnectionStatus::Error, null));
        $this->assertSame('Send a test message', $projector->recommendedAction(ConnectionType::ProviderRoute, ConnectionStatus::SetupRequired, null, true));
        $this->assertSame('Fix credentials', $projector->recommendedAction(ConnectionType::ProviderRoute, ConnectionStatus::SetupRequired, null, false));
        $this->assertSame('Provider temporarily unavailable', $projector->recommendedAction(ConnectionType::ProviderRoute, ConnectionStatus::Degraded, null));
        $this->assertNull($projector->recommendedAction(ConnectionType::ManagedNumber, ConnectionStatus::Ready, SessionStatus::Working));
    }
}
