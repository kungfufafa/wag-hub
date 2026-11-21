<?php

namespace App\Services\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;

final class ConnectionStatusProjector
{
    /**
     * Runner failures that mean the engine itself is down, not that the number is broken.
     */
    public const RUNNER_DOWN_ERRORS = ['engine_unavailable', 'engine_unconfigured', 'engine_unreachable'];

    /**
     * Session commands that take a number offline (SessionService::command).
     */
    public const TEARDOWN_ACTIONS = ['stop', 'logout', 'delete'];

    /**
     * Project a v2 engine session snapshot (SessionService::snapshot) onto a connection status.
     * A managed number is ready only while its session is WORKING, freshly observed, and idle.
     *
     * @param  array<string, mixed>|null  $session
     */
    public function projectEngineSession(?array $session): ConnectionStatus
    {
        if ($session === null) {
            return ConnectionStatus::SetupRequired;
        }

        if ($session['deleted'] ?? false) {
            return ConnectionStatus::Disconnected;
        }

        $status = (string) ($session['status'] ?? 'STOPPED');
        $running = ($session['desired_state'] ?? 'STOPPED') === 'RUNNING';
        $stale = (bool) ($session['stale'] ?? true);
        $pending = ($session['pending_operation'] ?? null) !== null;
        $action = $pending ? ($session['pending_operation']['action'] ?? null) : null;

        // A pending stop, logout or delete is a teardown: the number is going away, not connecting.
        if (in_array($action, self::TEARDOWN_ACTIONS, true)) {
            return ConnectionStatus::Disconnected;
        }

        if ($status === 'WORKING' && $running) {
            // An authenticated number whose runner is briefly unobserved is degraded, not lost.
            return $stale || $pending ? ConnectionStatus::Degraded : ConnectionStatus::Ready;
        }

        if (in_array($session['last_error'] ?? null, self::RUNNER_DOWN_ERRORS, true) && ($stale || $pending)) {
            return ConnectionStatus::SetupRequired;
        }

        if ($status === 'FAILED' && ! $pending) {
            return ConnectionStatus::Error;
        }

        if ($running || $pending || in_array($status, ['STARTING', 'SCAN_QR_CODE'], true)) {
            return ConnectionStatus::Connecting;
        }

        return ConnectionStatus::Disconnected;
    }

    /**
     * @param  array<string, mixed>|null  $session
     */
    public function recommendedEngineAction(ConnectionStatus $status, ?array $session): ?string
    {
        return match ($status) {
            ConnectionStatus::Ready => null,
            ConnectionStatus::Connecting => ($session['auth_method'] ?? null) === 'pairing'
                ? 'Enter pairing code'
                : 'Scan QR',
            ConnectionStatus::SetupRequired => $session === null ? 'Reconnect' : 'Start WAG Hub runner',
            ConnectionStatus::Degraded => 'Provider temporarily unavailable',
            ConnectionStatus::Disconnected, ConnectionStatus::Error => 'Reconnect',
        };
    }

    public function projectRoute(?string $health, bool $circuitOpen, bool $configured): ConnectionStatus
    {
        if (! $configured) {
            return $health === null
                ? ConnectionStatus::SetupRequired
                : ConnectionStatus::Disconnected;
        }

        if ($health === null || $health === 'unknown') {
            return ConnectionStatus::SetupRequired;
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

    public function recommendedRouteAction(ConnectionStatus $status, bool $configured = false): ?string
    {
        return match ($status) {
            ConnectionStatus::Ready => null,
            ConnectionStatus::SetupRequired => $configured
                ? 'Send a test message'
                : 'Fix credentials',
            ConnectionStatus::Degraded => 'Provider temporarily unavailable',
            ConnectionStatus::Error, ConnectionStatus::Disconnected, ConnectionStatus::Connecting => 'Fix credentials',
        };
    }

    /**
     * @param  array<string, mixed>|null  $session
     */
    public function recommendedAction(
        ConnectionType $type,
        ConnectionStatus $status,
        ?array $session = null,
        bool $configured = false,
    ): ?string {
        return $type === ConnectionType::ManagedNumber
            ? $this->recommendedEngineAction($status, $session)
            : $this->recommendedRouteAction($status, $configured);
    }
}
