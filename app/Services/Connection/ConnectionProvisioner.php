<?php

namespace App\Services\Connection;

use App\Domain\Connection\ConnectionStatus;
use App\Domain\Connection\ConnectionType;
use App\Exceptions\ConnectionException;
use App\Infrastructure\WhatsApp\BaileysClient;
use App\Infrastructure\WhatsApp\ProviderEndpointGuard;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\RoutingStep;
use App\Models\WhatsAppConnection;
use App\Services\WhatsAppEngineService;
use App\Services\WhatsAppSessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ConnectionProvisioner
{
    public function __construct(
        private readonly BaileysClient $native,
        private readonly ConnectionStatusResolver $statusResolver,
        private readonly ProviderEndpointGuard $endpointGuard,
    ) {}

    public function createManagedNumber(
        ClientApplication $application,
        string $name,
        ?string $sessionId = null,
        bool $makeDefault = false,
    ): WhatsAppConnection {
        $sessionId = $this->normalizeSessionId($sessionId ?? $this->defaultSessionId($application));

        return DB::transaction(function () use ($application, $name, $sessionId, $makeDefault): WhatsAppConnection {
            $existing = $this->findBySession($application, $sessionId);

            if ($existing !== null) {
                return $this->statusResolver->refresh($existing);
            }

            $connection = $this->newConnection(
                application: $application,
                name: $name,
                type: ConnectionType::ManagedNumber,
                slug: $this->connectionSlug($application, $sessionId),
                sessionId: $sessionId,
                driver: $this->defaultEngineDriver(),
                makeDefault: $makeDefault,
            );

            return $this->statusResolver->refresh($connection);
        });
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    public function createProviderRoute(
        ClientApplication $application,
        string $name,
        string $driver,
        array $configuration,
        bool $makeDefault = false,
    ): WhatsAppConnection {
        if (! in_array($driver, ['waha', 'fonnte', 'gowa', 'waba', 'wag_hub'], true)) {
            throw new ConnectionException('Provider tidak dikenali.', 422, 'capability_not_supported');
        }

        $this->validateProviderConfiguration($driver, $configuration);

        return DB::transaction(function () use ($application, $name, $driver, $configuration, $makeDefault): WhatsAppConnection {
            $slug = $this->uniqueSlug($application, Str::slug($name));

            $provider = new ProviderAccount;
            $provider->forceFill([
                'uuid' => (string) Str::uuid(),
                'name' => $application->name.' · '.$name,
                'slug' => 'conn-'.$slug,
                'driver' => $driver,
                'configuration' => array_merge($configuration, [
                    'owned_by_application_id' => $application->getKey(),
                    'connection_provisioned' => true,
                ]),
                'is_active' => true,
                'health_status' => 'unknown',
                'timeout_seconds' => 20,
            ]);
            $provider->save();

            $policy = new RoutingPolicy;
            $policy->forceFill([
                'uuid' => (string) Str::uuid(),
                'client_application_id' => $application->getKey(),
                'operation' => 'message',
                'key' => 'conn-'.$slug,
                'purpose' => null,
                'name' => $application->name.' · '.$name,
                'is_default' => false,
                'is_active' => true,
            ]);
            $policy->save();

            RoutingStep::query()->updateOrCreate(
                [
                    'routing_policy_id' => $policy->id,
                    'provider_account_id' => $provider->id,
                ],
                [
                    'position' => 1,
                    'is_active' => true,
                ],
            );

            $connection = $this->newConnection(
                application: $application,
                name: $name,
                type: ConnectionType::ProviderRoute,
                slug: $slug,
                sessionId: null,
                driver: $driver,
                makeDefault: $makeDefault,
                provider: $provider,
                routingPolicy: $policy,
            );

            return $this->statusResolver->refresh($connection);
        });
    }

    public function startManagedSetup(
        WhatsAppConnection $connection,
        string $mode,
        ?string $phone = null,
    ): WhatsAppConnection {
        if (! $connection->isManagedNumber()) {
            throw new ConnectionException('Koneksi ini bukan nomor terkelola.', 422, 'capability_not_supported');
        }

        $application = $connection->clientApplication;
        $sessionId = (string) $connection->session_id;

        if ($sessionId === '') {
            throw new ConnectionException('Sesi WhatsApp belum ditentukan.', 422, 'connection_not_ready');
        }

        $engine = app(WhatsAppEngineService::class);
        $engine->startSession($application, $sessionId, $mode, $phone);

        $account = ProviderAccount::query()
            ->whereIn('driver', ['wag_hub', 'waha'])
            ->get()
            ->first(fn (ProviderAccount $provider): bool => (string) ($provider->configuration['owned_by_application_id'] ?? '') === (string) $application->getKey()
                && ($provider->configuration['engine_session_id'] ?? $provider->configuration['cesa_session_id'] ?? null) === $sessionId);

        if ($account !== null) {
            $connection->forceFill([
                'provider_account_id' => $account->id,
                'routing_policy_id' => RoutingPolicy::query()
                    ->where('client_application_id', $application->getKey())
                    ->where('key', $sessionId)
                    ->value('id'),
                'provisioning_state' => array_merge($connection->provisioning_state ?? [], [
                    'mode' => $mode,
                    'started_at' => now()->toIso8601String(),
                ]),
            ])->save();
        }

        return $this->statusResolver->refresh($connection->fresh() ?? $connection);
    }

    public function validateProviderRoute(WhatsAppConnection $connection): WhatsAppConnection
    {
        if (! $connection->isProviderRoute()) {
            throw new ConnectionException('Koneksi ini bukan provider eksternal.', 422, 'capability_not_supported');
        }

        $provider = $connection->providerAccount;

        if ($provider === null) {
            throw new ConnectionException('Provider belum disiapkan.', 422, 'connection_not_ready', nextAction: 'retry_setup');
        }

        return $this->statusResolver->refresh($connection);
    }

    public function setDefault(WhatsAppConnection $connection): WhatsAppConnection
    {
        DB::transaction(function () use ($connection): void {
            WhatsAppConnection::query()
                ->where('client_application_id', $connection->client_application_id)
                ->whereKeyNot($connection->getKey())
                ->update(['is_default' => false]);

            $connection->forceFill(['is_default' => true])->save();
        });

        return $connection->fresh() ?? $connection;
    }

    private function newConnection(
        ClientApplication $application,
        string $name,
        ConnectionType $type,
        string $slug,
        ?string $sessionId,
        ?string $driver,
        bool $makeDefault,
        ?ProviderAccount $provider = null,
        ?RoutingPolicy $routingPolicy = null,
    ): WhatsAppConnection {
        if ($makeDefault) {
            WhatsAppConnection::query()
                ->where('client_application_id', $application->getKey())
                ->update(['is_default' => false]);
        } elseif (! WhatsAppConnection::query()->where('client_application_id', $application->getKey())->exists()) {
            $makeDefault = true;
        }

        $connection = new WhatsAppConnection;
        $connection->forceFill([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $application->getKey(),
            'name' => $name,
            'slug' => $slug,
            'type' => $type->value,
            'status' => ConnectionStatus::SetupRequired->value,
            'is_default' => $makeDefault,
            'provider_account_id' => $provider?->id,
            'routing_policy_id' => $routingPolicy?->id,
            'session_id' => $sessionId,
            'driver' => $driver,
            'provisioning_state' => [
                'created_at' => now()->toIso8601String(),
            ],
        ]);
        $connection->save();

        return $connection;
    }

    private function findBySession(ClientApplication $application, string $sessionId): ?WhatsAppConnection
    {
        return WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('session_id', $sessionId)
            ->first();
    }

    private function defaultSessionId(ClientApplication $application): string
    {
        return 'primary';
    }

    private function normalizeSessionId(string $sessionId): string
    {
        if (preg_match('/\A[a-z][a-z0-9-]{1,46}\z/', $sessionId) !== 1 || str_contains($sessionId, '--') || str_ends_with($sessionId, '-')) {
            throw new ConnectionException('ID sesi WhatsApp tidak valid.', 422, 'recipient_invalid');
        }

        return $sessionId;
    }

    private function connectionSlug(ClientApplication $application, string $sessionId): string
    {
        $base = $sessionId === 'primary' ? 'default' : $sessionId;

        return $this->uniqueSlug($application, $base);
    }

    private function uniqueSlug(ClientApplication $application, string $base): string
    {
        $slug = Str::slug($base);

        if ($slug === '') {
            $slug = 'connection';
        }

        if (! WhatsAppConnection::query()->where('client_application_id', $application->getKey())->where('slug', $slug)->exists()) {
            return $slug;
        }

        return $slug.'-'.Str::lower(Str::random(4));
    }

    private function defaultEngineDriver(): string
    {
        return in_array(config('gateway.engine.driver', 'wag_hub'), ['wag_hub', 'baileys'], true)
            ? 'wag_hub'
            : 'waha';
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function validateProviderConfiguration(string $driver, array $configuration): void
    {
        try {
            match ($driver) {
                'waha' => $this->endpointGuard->assertAllowed((string) ($configuration['base_url'] ?? '')),
                'fonnte' => $this->endpointGuard->assertAllowed((string) ($configuration['endpoint'] ?? '')),
                'gowa' => $this->endpointGuard->assertAllowed((string) ($configuration['base_url'] ?? '')),
                'waba' => $this->endpointGuard->assertAllowed((string) ($configuration['base_url'] ?? 'https://graph.facebook.com')),
                'wag_hub' => null,
                default => throw new InvalidArgumentException('Unsupported driver'),
            };
        } catch (InvalidArgumentException $exception) {
            throw new ConnectionException($exception->getMessage(), 422, 'authentication_failed', nextAction: 'fix_credentials');
        }
    }
}
