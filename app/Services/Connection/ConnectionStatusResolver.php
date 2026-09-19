<?php

namespace App\Services\Connection;

use App\Domain\Connection\ConnectionNextAction;
use App\Domain\Connection\ConnectionStatus;
use App\Domain\Connection\ConnectionType;
use App\Domain\WhatsApp\SessionStatus;
use App\Infrastructure\WhatsApp\BaileysClient;
use App\Models\ProviderAccount;
use App\Models\WhatsAppConnection;
use Illuminate\Support\Facades\DB;

final class ConnectionStatusResolver
{
    public function __construct(
        private readonly BaileysClient $native,
        private readonly ConnectionCapabilityResolver $capabilities,
    ) {}

    public function refresh(WhatsAppConnection $connection): WhatsAppConnection
    {
        $provider = $connection->providerAccount;

        if ($provider === null) {
            return $this->apply($connection, ConnectionStatus::SetupRequired, ConnectionNextAction::RetrySetup, 'Koneksi belum disiapkan.');
        }

        if ($connection->connectionType() === ConnectionType::ManagedNumber) {
            return $this->refreshManagedNumber($connection, $provider);
        }

        return $this->refreshProviderRoute($connection, $provider);
    }

    private function refreshManagedNumber(WhatsAppConnection $connection, ProviderAccount $provider): WhatsAppConnection
    {
        if (! $provider->is_active) {
            return $this->apply($connection, ConnectionStatus::Disconnected, ConnectionNextAction::Reconnect, 'Nomor WhatsApp terputus.');
        }

        $session = $provider->sessionStatus();

        if ($session === SessionStatus::Working) {
            $health = $this->healthSummary($provider);

            return $this->apply(
                $connection,
                $health['operational'] === 'degraded' ? ConnectionStatus::Degraded : ConnectionStatus::Ready,
                ConnectionNextAction::None,
                null,
                $this->senderIdentity($provider),
                $health,
            );
        }

        if (in_array($session, [SessionStatus::ScanQr, SessionStatus::Starting], true)) {
            $mode = $provider->configuration['engine_mode'] ?? $provider->configuration['cesa_mode'] ?? 'qr';

            return $this->apply(
                $connection,
                ConnectionStatus::Connecting,
                $mode === 'pairing' ? ConnectionNextAction::EnterPairingCode : ConnectionNextAction::ScanQr,
                'Selesaikan login WhatsApp untuk melanjutkan.',
                $this->senderIdentity($provider),
            );
        }

        if ($session === SessionStatus::Failed) {
            return $this->apply($connection, ConnectionStatus::Error, ConnectionNextAction::Reconnect, 'Sesi WhatsApp gagal terhubung.');
        }

        if ($provider->driver === 'wag_hub' && ! $this->native->isConfigured()) {
            return $this->apply($connection, ConnectionStatus::Error, ConnectionNextAction::StartRunner, 'WAG Hub runner belum dikonfigurasi.');
        }

        return $this->apply($connection, ConnectionStatus::SetupRequired, ConnectionNextAction::ScanQr, 'Hubungkan nomor WhatsApp Anda.');
    }

    private function refreshProviderRoute(WhatsAppConnection $connection, ProviderAccount $provider): WhatsAppConnection
    {
        if (! $provider->is_active) {
            return $this->apply($connection, ConnectionStatus::Error, ConnectionNextAction::FixCredentials, 'Provider tidak aktif.');
        }

        if ($provider->circuit_open_until !== null && $provider->circuit_open_until->isFuture()) {
            return $this->apply(
                $connection,
                ConnectionStatus::Degraded,
                ConnectionNextAction::ProviderUnavailable,
                'Provider sementara tidak tersedia karena circuit breaker.',
                null,
                $this->healthSummary($provider),
            );
        }

        if ($provider->health_status === 'unhealthy') {
            return $this->apply(
                $connection,
                ConnectionStatus::Degraded,
                ConnectionNextAction::ProviderUnavailable,
                'Provider melaporkan kondisi tidak sehat.',
                null,
                $this->healthSummary($provider),
            );
        }

        return $this->apply(
            $connection,
            ConnectionStatus::Ready,
            ConnectionNextAction::None,
            null,
            $this->senderIdentity($provider),
            $this->healthSummary($provider),
        );
    }

    /**
     * @param  array<string, mixed>|null  $healthSummary
     * @param  array<string, mixed>|null  $senderIdentity
     */
    private function apply(
        WhatsAppConnection $connection,
        ConnectionStatus $status,
        ConnectionNextAction $nextAction,
        ?string $detail = null,
        ?array $senderIdentity = null,
        ?array $healthSummary = null,
    ): WhatsAppConnection {
        $provider = $connection->providerAccount;
        $resolvedCapabilities = $provider !== null
            ? $this->capabilities->resolve($connection, $provider)
            : ($connection->capabilities ?? []);

        $connection->forceFill([
            'status' => $status->value,
            'next_action' => $nextAction === ConnectionNextAction::None ? null : $nextAction->value,
            'status_detail' => $detail,
            'sender_identity' => $senderIdentity ?? $connection->sender_identity,
            'health_summary' => $healthSummary ?? $connection->health_summary,
            'capabilities' => $resolvedCapabilities,
        ])->save();

        return $connection->fresh() ?? $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function senderIdentity(ProviderAccount $provider): array
    {
        $meta = is_array($provider->session_meta) ? $provider->session_meta : [];

        return array_filter([
            'phone' => $this->digitsFromMeta($meta['phone'] ?? null),
            'push_name' => is_string($meta['push_name'] ?? null) ? $meta['push_name'] : null,
            'driver' => $provider->driver,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function healthSummary(ProviderAccount $provider): array
    {
        $recentFailures = DB::table('message_attempts')
            ->where('provider_account_id', $provider->id)
            ->where('created_at', '>=', now()->subDay())
            ->selectRaw("sum(case when status = 'failed' then 1 else 0 end) as failures")
            ->selectRaw('count(*) as total')
            ->first();

        $total = (int) ($recentFailures?->total ?? 0);
        $failures = (int) ($recentFailures?->failures ?? 0);
        $failureRate = $total > 0 ? round($failures / $total, 4) : 0.0;

        $operational = match (true) {
            $provider->circuit_open_until !== null && $provider->circuit_open_until->isFuture() => 'down',
            $provider->health_status === 'unhealthy' || $failureRate >= 0.5 => 'degraded',
            default => 'ready',
        };

        return [
            'operational' => $operational,
            'health_status' => $provider->health_status,
            'failure_rate_24h' => $failureRate,
            'circuit_open_until' => $provider->circuit_open_until?->toIso8601String(),
            'last_successful_send_at' => $provider->acceptedMessages()
                ->latest('provider_accepted_at')
                ->value('provider_accepted_at'),
        ];
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
