<?php

namespace App\Services\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Domain\WhatsApp\SessionStatus;

final class ConnectionStatusProjector
{
    public function projectManaged(?SessionStatus $session, string $health, bool $circuitOpen): ConnectionStatus
    {
        if ($session === null) {
            return ConnectionStatus::SetupRequired;
        }

        return match ($session) {
            SessionStatus::ScanQr, SessionStatus::Starting => ConnectionStatus::Connecting,
            SessionStatus::Working => ($circuitOpen || $health === 'degraded')
                ? ConnectionStatus::Degraded
                : ConnectionStatus::Ready,
            SessionStatus::Failed => ConnectionStatus::Error,
            SessionStatus::Stopped => ConnectionStatus::Disconnected,
            SessionStatus::Unknown => ConnectionStatus::SetupRequired,
        };
    }

    public function projectRoute(?string $health, bool $circuitOpen, bool $configured): ConnectionStatus
    {
        if ($health === null) {
            return ConnectionStatus::SetupRequired;
        }

        if (! $configured) {
            return ConnectionStatus::Disconnected;
        }

        if ($health === 'unavailable') {
            return ConnectionStatus::Error;
        }

        if ($circuitOpen || $health === 'degraded') {
            return ConnectionStatus::Degraded;
        }

        if ($health === 'healthy') {
            return ConnectionStatus::Ready;
        }

        return ConnectionStatus::Disconnected;
    }

    public function recommendedAction(
        ConnectionType $type,
        ConnectionStatus $status,
        ?SessionStatus $session = null,
    ): ?string {
        if ($status === ConnectionStatus::Ready) {
            return null;
        }

        if ($type === ConnectionType::ManagedNumber) {
            return match ($status) {
                ConnectionStatus::Connecting => $session === SessionStatus::ScanQr || $session === null
                    ? 'Scan QR'
                    : 'Enter pairing code',
                ConnectionStatus::SetupRequired => 'Start WAG Hub runner',
                ConnectionStatus::Disconnected => 'Reconnect',
                ConnectionStatus::Degraded => 'Provider temporarily unavailable',
                ConnectionStatus::Error => 'Reconnect',
                default => null,
            };
        }

        return match ($status) {
            ConnectionStatus::SetupRequired => 'Fix credentials',
            ConnectionStatus::Error => 'Fix credentials',
            ConnectionStatus::Degraded => 'Provider temporarily unavailable',
            ConnectionStatus::Disconnected => 'Fix credentials',
            ConnectionStatus::Connecting => 'Fix credentials',
            default => null,
        };
    }
}
