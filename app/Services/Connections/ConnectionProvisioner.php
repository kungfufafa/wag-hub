<?php

namespace App\Services\Connections;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Exceptions\WhatsAppConnectionException;
use App\Exceptions\WhatsAppEngineException;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\RoutingStep;
use App\Models\WhatsAppConnection;
use App\Services\WhatsAppEngineService;
use App\Services\WhatsAppSessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class ConnectionProvisioner
{
    public function __construct(
        private WhatsAppEngineService $engine,
        private WhatsAppSessionManager $sessions,
        private ProviderConfigurationValidator $validator,
        private ConnectionHealthProjector $health,
        private ConnectionCapabilityCatalog $capabilities,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function provision(ClientApplication $application, array $input): WhatsAppConnection
    {
        $type = ConnectionType::from((string) ($input['type'] ?? ConnectionType::ManagedNumber->value));
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $name = $type === ConnectionType::ManagedNumber ? 'WhatsApp' : 'Provider';
        }

        $connection = $this->findOrCreate($application, $name, $type, (bool) ($input['is_default'] ?? false));

        try {
            if ($type === ConnectionType::ManagedNumber) {
                $this->provisionManaged($connection, $input);
            } else {
                $this->provisionProvider($connection, $input);
            }
        } catch (WhatsAppConnectionException $exception) {
            $this->markRecoverableFailure($connection, $exception->errorCode, $exception->getMessage());

            throw $exception;
        } catch (WhatsAppEngineException $exception) {
            $code = $exception->errorCode === 'engine_unavailable' || $exception->errorCode === 'engine_unconfigured'
                ? 'connection_not_ready'
                : $exception->errorCode;
            $this->markRecoverableFailure($connection, $code, $exception->getMessage());

            throw new WhatsAppConnectionException(
                $exception->getMessage(),
                $exception->statusCode,
                $code,
                $exception->retryable,
            );
        }

        return $this->health->refresh($connection->fresh() ?? $connection);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function connect(WhatsAppConnection $connection, array $input = []): WhatsAppConnection
    {
        $mode = ($input['mode'] ?? 'qr') === 'pairing' ? 'pairing' : 'qr';
        $phone = is_string($input['phone'] ?? null) ? (string) $input['phone'] : null;

        if ($connection->provider_account_id === null) {
            return $this->provision($connection->clientApplication, [
                'name' => $connection->name,
                'type' => $connection->type,
                'is_default' => $connection->is_default,
                'mode' => $mode,
                'phone' => $phone,
                'provider' => [
                    'driver' => $input['driver'] ?? $connection->providerAccount?->driver,
                    'configuration' => $input['configuration'] ?? [],
                ],
            ]);
        }

        $account = $connection->providerAccount;

        if ($account === null || ! $account->supportsSessions()) {
            return $this->health->refresh($connection);
        }

        $this->sessions->connect($account, $mode === 'pairing' ? $phone : null);
        $connection->forceFill([
            'setup_state' => array_merge($connection->setup_state ?? [], [
                'session_id' => $connection->sessionId(),
                'last_step' => 'connecting',
                'mode' => $mode,
            ]),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return $this->health->refresh($connection->fresh() ?? $connection);
    }

    public function retry(WhatsAppConnection $connection): WhatsAppConnection
    {
        return $this->provision($connection->clientApplication, [
            'name' => $connection->name,
            'type' => $connection->type,
            'is_default' => $connection->is_default,
            'mode' => $connection->setup_state['mode'] ?? 'qr',
            'phone' => $connection->setup_state['phone'] ?? null,
            'provider' => [
                'driver' => $connection->providerAccount?->driver,
                'configuration' => $connection->providerAccount?->configuration ?? [],
            ],
        ]);
    }

    private function findOrCreate(
        ClientApplication $application,
        string $name,
        ConnectionType $type,
        bool $makeDefault,
    ): WhatsAppConnection {
        return DB::transaction(function () use ($application, $name, $type, $makeDefault): WhatsAppConnection {
            ClientApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            $connection = WhatsAppConnection::query()
                ->where('client_application_id', $application->getKey())
                ->where('name', $name)
                ->first();

            if ($connection === null) {
                $connection = new WhatsAppConnection;
                $connection->forceFill([
                    'uuid' => (string) Str::uuid(),
                    'client_application_id' => $application->getKey(),
                    'name' => $name,
                    'type' => $type->value,
                    'status' => ConnectionStatus::SetupRequired->value,
                    'is_default' => false,
                    'capabilities' => $this->capabilities->forDriver(
                        $type === ConnectionType::ManagedNumber ? 'wag_hub' : 'waha',
                    ),
                    'setup_state' => [],
                ]);
                $connection->save();
            }

            if ($makeDefault || ! WhatsAppConnection::query()
                ->where('client_application_id', $application->getKey())
                ->where('is_default', true)
                ->whereKeyNot($connection->getKey())
                ->exists()) {
                $this->markDefault($connection);
            }

            return $connection->fresh() ?? $connection;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function provisionManaged(WhatsAppConnection $connection, array $input): void
    {
        $mode = ($input['mode'] ?? 'qr') === 'pairing' ? 'pairing' : 'qr';
        $phone = is_string($input['phone'] ?? null) ? (string) $input['phone'] : null;
        $sessionId = $connection->sessionId();

        $account = $this->engine->provisionOwnedSession(
            $connection->clientApplication,
            $sessionId,
            $mode,
        );
        $policyId = $this->engine->sessionRouteIdFor($connection->clientApplication, $sessionId, $account);

        $connection->forceFill([
            'provider_account_id' => $account->getKey(),
            'routing_policy_id' => $policyId,
            'setup_state' => [
                'session_id' => $sessionId,
                'last_step' => 'provisioned',
                'mode' => $mode,
                'phone' => $phone,
            ],
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        $this->sessions->connect($account, $mode === 'pairing' ? $phone : null);
        $connection->forceFill([
            'setup_state' => array_merge($connection->setup_state ?? [], ['last_step' => 'connecting']),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function provisionProvider(WhatsAppConnection $connection, array $input): void
    {
        $provider = is_array($input['provider'] ?? null) ? $input['provider'] : [];
        $driver = strtolower(trim((string) ($provider['driver'] ?? $connection->providerAccount?->driver ?? '')));
        $configuration = is_array($provider['configuration'] ?? null) ? $provider['configuration'] : [];

        if ($driver === 'wag_hub') {
            $this->provisionManaged($connection, $input);
            $this->ensureDefaultMessageRoute($connection);

            return;
        }

        $existing = $connection->providerAccount?->configuration ?? [];
        $validated = $this->validator->validate($driver, $configuration, $existing);

        DB::transaction(function () use ($connection, $driver, $validated): void {
            $account = $this->reuseOrCreateProviderAccount($connection, $driver, $validated);
            $policy = $this->reuseOrCreateMessagePolicy($connection, $account);
            $lookupPolicy = $this->reuseOrCreateNumberCheckPolicy($connection, $account, $driver);

            $connection->forceFill([
                'provider_account_id' => $account->getKey(),
                'routing_policy_id' => $policy->getKey(),
                'number_check_policy_id' => $lookupPolicy?->getKey(),
                'setup_state' => [
                    'last_step' => 'validated',
                    'driver' => $driver,
                ],
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function reuseOrCreateProviderAccount(
        WhatsAppConnection $connection,
        string $driver,
        array $configuration,
    ): ProviderAccount {
        $account = $connection->providerAccount;

        if ($account !== null) {
            $account->forceFill([
                'driver' => $driver,
                'configuration' => array_merge($account->configuration ?? [], $configuration, [
                    'whatsapp_connection_id' => $connection->getKey(),
                ]),
                'is_active' => true,
            ])->save();

            return $account;
        }

        $account = ProviderAccount::query()
            ->get()
            ->first(function (ProviderAccount $candidate) use ($connection): bool {
                return (string) ($candidate->configuration['whatsapp_connection_id'] ?? '') === (string) $connection->getKey();
            });

        if ($account !== null) {
            $account->forceFill([
                'driver' => $driver,
                'configuration' => array_merge($account->configuration ?? [], $configuration),
                'is_active' => true,
            ])->save();

            return $account;
        }

        $account = new ProviderAccount;
        $account->forceFill([
            'uuid' => (string) Str::uuid(),
            'name' => $connection->clientApplication->name.' '.$connection->name,
            'slug' => 'conn-acct-'.hash('sha256', $connection->uuid),
            'driver' => $driver,
            'configuration' => array_merge($configuration, [
                'whatsapp_connection_id' => $connection->getKey(),
            ]),
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);
        $account->save();

        return $account;
    }

    private function reuseOrCreateMessagePolicy(WhatsAppConnection $connection, ProviderAccount $account): RoutingPolicy
    {
        if ($connection->routing_policy_id !== null) {
            $existing = RoutingPolicy::query()->find($connection->routing_policy_id);

            if ($existing !== null) {
                $this->ensureStep($existing, $account);

                return $existing;
            }
        }

        $default = RoutingPolicy::query()
            ->where('client_application_id', $connection->client_application_id)
            ->where('operation', 'message')
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        if ($default !== null) {
            $this->ensureStep($default, $account);

            return $default;
        }

        $policy = new RoutingPolicy;
        $policy->forceFill([
            'client_application_id' => $connection->client_application_id,
            'operation' => 'message',
            'key' => 'default',
            'purpose' => null,
            'name' => $connection->name,
            'is_default' => true,
            'is_active' => true,
        ]);
        $policy->save();
        $this->ensureStep($policy, $account);

        return $policy;
    }

    private function reuseOrCreateNumberCheckPolicy(
        WhatsAppConnection $connection,
        ProviderAccount $account,
        string $driver,
    ): ?RoutingPolicy {
        if (! in_array($driver, ['wag_hub', 'waha', 'fonnte', 'gowa'], true)) {
            return $connection->numberCheckPolicy;
        }

        if ($connection->number_check_policy_id !== null) {
            $existing = RoutingPolicy::query()->find($connection->number_check_policy_id);

            if ($existing !== null) {
                $this->ensureStep($existing, $account);

                return $existing;
            }
        }

        $existing = RoutingPolicy::query()
            ->where('client_application_id', $connection->client_application_id)
            ->where('operation', 'number_check')
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();

        if ($existing !== null) {
            $this->ensureStep($existing, $account);

            return $existing;
        }

        $policy = new RoutingPolicy;
        $policy->forceFill([
            'client_application_id' => $connection->client_application_id,
            'operation' => 'number_check',
            'key' => 'default',
            'purpose' => null,
            'name' => $connection->name.' lookup',
            'is_default' => true,
            'is_active' => true,
        ]);
        $policy->save();
        $this->ensureStep($policy, $account);

        return $policy;
    }

    private function ensureDefaultMessageRoute(WhatsAppConnection $connection): void
    {
        $account = $connection->providerAccount;

        if ($account === null) {
            return;
        }

        $policy = $this->reuseOrCreateMessagePolicy($connection, $account);
        $connection->forceFill(['routing_policy_id' => $policy->getKey()])->save();
    }

    private function ensureStep(RoutingPolicy $policy, ProviderAccount $account): void
    {
        $existing = RoutingStep::query()
            ->where('routing_policy_id', $policy->getKey())
            ->where('provider_account_id', $account->getKey())
            ->first();

        if ($existing !== null) {
            if (! $existing->is_active) {
                $existing->forceFill(['is_active' => true])->save();
            }

            return;
        }

        $position = ((int) RoutingStep::query()->where('routing_policy_id', $policy->getKey())->max('position')) + 1;

        RoutingStep::query()->create([
            'routing_policy_id' => $policy->getKey(),
            'provider_account_id' => $account->getKey(),
            'position' => max(1, $position),
            'is_active' => true,
        ]);
    }

    private function markDefault(WhatsAppConnection $connection): void
    {
        WhatsAppConnection::query()
            ->where('client_application_id', $connection->client_application_id)
            ->whereKeyNot($connection->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $connection->forceFill(['is_default' => true])->save();
    }

    private function markRecoverableFailure(WhatsAppConnection $connection, string $code, string $message): void
    {
        try {
            $connection->forceFill([
                'status' => $code === 'connection_not_ready'
                    ? ConnectionStatus::SetupRequired->value
                    : ConnectionStatus::Error->value,
                'last_error_code' => $code,
                'last_error_message' => $message,
                'recommended_action' => $code === 'connection_not_ready'
                    ? 'Start WAG Hub runner'
                    : 'Fix credentials',
                'setup_state' => array_merge($connection->setup_state ?? [], [
                    'last_step' => 'failed',
                    'error' => $code,
                ]),
            ])->save();
        } catch (Throwable) {
            // The connection row is the recovery handle; ignore secondary persist failures.
        }
    }
}
