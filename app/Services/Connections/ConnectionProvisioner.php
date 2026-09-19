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
    public function provision(ClientApplication $application, array $input): ProvisionedConnection
    {
        $type = ConnectionType::from((string) ($input['type'] ?? ConnectionType::ManagedNumber->value));
        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            $name = $type === ConnectionType::ManagedNumber ? 'WhatsApp' : 'Provider';
        }

        $this->assertPairingPhone($type, $input);

        [$connection, $created] = $this->findOrCreate(
            $application,
            $name,
            $type,
            (bool) ($input['is_default'] ?? false),
        );

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

        return new ProvisionedConnection(
            $this->health->refresh($connection->fresh() ?? $connection),
            $created,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function connect(WhatsAppConnection $connection, array $input = []): WhatsAppConnection
    {
        $mode = ($input['mode'] ?? 'qr') === 'pairing' ? 'pairing' : 'qr';
        $phone = is_string($input['phone'] ?? null) ? trim((string) $input['phone']) : null;

        if ($connection->typeEnum() === ConnectionType::ManagedNumber) {
            $this->assertPairingPhone($connection->typeEnum(), ['mode' => $mode, 'phone' => $phone]);
        }

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
            ])->connection;
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
        ])->connection;
    }

    /**
     * @return array{0: WhatsAppConnection, 1: bool}
     */
    private function findOrCreate(
        ClientApplication $application,
        string $name,
        ConnectionType $type,
        bool $makeDefault,
    ): array {
        return DB::transaction(function () use ($application, $name, $type, $makeDefault): array {
            ClientApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            $connection = WhatsAppConnection::query()
                ->where('client_application_id', $application->getKey())
                ->where('name', $name)
                ->first();
            $created = false;

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
                $created = true;
            } elseif ($connection->type !== $type->value) {
                throw new WhatsAppConnectionException(
                    'A connection named '.$name.' already exists as '.$connection->type.'.',
                    409,
                    'capability_not_supported',
                );
            }

            if ($makeDefault || ! WhatsAppConnection::query()
                ->where('client_application_id', $application->getKey())
                ->where('is_default', true)
                ->whereKeyNot($connection->getKey())
                ->exists()) {
                $this->markDefault($connection);
            }

            return [$connection->fresh() ?? $connection, $created];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function provisionManaged(WhatsAppConnection $connection, array $input): void
    {
        $mode = ($input['mode'] ?? 'qr') === 'pairing' ? 'pairing' : 'qr';
        $phone = is_string($input['phone'] ?? null) ? trim((string) $input['phone']) : null;
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

            return;
        }

        $existing = $connection->providerAccount?->configuration ?? [];
        $validated = $this->validator->validate($driver, $configuration, $existing);

        DB::transaction(function () use ($connection, $driver, $validated): void {
            $account = $this->reuseOrCreateProviderAccount($connection, $driver, $validated);
            $policy = $this->dedicatedPolicy($connection, $account, 'message', $connection->routing_policy_id);
            $lookupPolicy = $this->dedicatedNumberCheckPolicy($connection, $account, $driver);

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
            ->where('slug', $this->accountSlug($connection))
            ->first();

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

        $account = new ProviderAccount;
        $account->forceFill([
            'uuid' => (string) Str::uuid(),
            'name' => $connection->clientApplication->name.' '.$connection->name,
            'slug' => $this->accountSlug($connection),
            'driver' => $driver,
            'configuration' => array_merge($configuration, [
                'whatsapp_connection_id' => $connection->getKey(),
            ]),
            'is_active' => true,
            'health_status' => 'unknown',
            'timeout_seconds' => 15,
        ]);
        $account->save();

        return $account;
    }

    private function dedicatedPolicy(
        WhatsAppConnection $connection,
        ProviderAccount $account,
        string $operation,
        ?int $existingPolicyId,
        string $nameSuffix = '',
    ): RoutingPolicy {
        if ($existingPolicyId !== null) {
            $existing = RoutingPolicy::query()->find($existingPolicyId);

            if ($existing !== null) {
                $this->ensureOwnedStep($existing, $account);

                return $existing;
            }
        }

        $key = $this->policyKey($connection, $operation);
        $makeDefault = $this->shouldBecomeDefaultPolicy($connection, $operation);

        if ($makeDefault && $this->policyKeyAvailable($connection, $operation, 'default')) {
            $key = 'default';
        }

        $policy = new RoutingPolicy;
        $policy->forceFill([
            'client_application_id' => $connection->client_application_id,
            'operation' => $operation,
            'key' => $key,
            'purpose' => null,
            'name' => trim($connection->name.($nameSuffix !== '' ? ' '.$nameSuffix : '')),
            'is_default' => $makeDefault,
            'is_active' => true,
        ]);
        $policy->save();
        $this->ensureOwnedStep($policy, $account);

        return $policy;
    }

    private function dedicatedNumberCheckPolicy(
        WhatsAppConnection $connection,
        ProviderAccount $account,
        string $driver,
    ): ?RoutingPolicy {
        if (! in_array($driver, ['wag_hub', 'waha', 'fonnte', 'gowa'], true)) {
            return $connection->numberCheckPolicy;
        }

        return $this->dedicatedPolicy(
            $connection,
            $account,
            'number_check',
            $connection->number_check_policy_id,
            'lookup',
        );
    }

    private function policyKey(WhatsAppConnection $connection, string $operation): string
    {
        $suffix = $operation === 'number_check' ? ':lookup' : '';

        return 'conn-'.substr(hash('sha256', $connection->uuid.$suffix), 0, 16);
    }

    private function shouldBecomeDefaultPolicy(WhatsAppConnection $connection, string $operation): bool
    {
        if (! $connection->is_default) {
            return false;
        }

        return ! RoutingPolicy::query()
            ->where('client_application_id', $connection->client_application_id)
            ->where('operation', $operation)
            ->where('is_default', true)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function policyKeyAvailable(
        WhatsAppConnection $connection,
        string $operation,
        string $key,
    ): bool {
        return ! RoutingPolicy::query()
            ->where('client_application_id', $connection->client_application_id)
            ->where('operation', $operation)
            ->where('key', $key)
            ->whereNull('deleted_at')
            ->exists();
    }

    private function ensureOwnedStep(RoutingPolicy $policy, ProviderAccount $account): void
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

        if (RoutingStep::query()->where('routing_policy_id', $policy->getKey())->exists()) {
            return;
        }

        RoutingStep::query()->create([
            'routing_policy_id' => $policy->getKey(),
            'provider_account_id' => $account->getKey(),
            'position' => 1,
            'is_active' => true,
        ]);
    }

    private function accountSlug(WhatsAppConnection $connection): string
    {
        return 'conn-acct-'.hash('sha256', $connection->uuid);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function assertPairingPhone(ConnectionType $type, array $input): void
    {
        if ($type !== ConnectionType::ManagedNumber) {
            return;
        }

        $mode = ($input['mode'] ?? 'qr') === 'pairing' ? 'pairing' : 'qr';
        $phone = is_string($input['phone'] ?? null) ? trim((string) $input['phone']) : '';

        if ($mode === 'pairing' && $phone === '') {
            throw new WhatsAppConnectionException(
                'Pairing requires a phone number.',
                422,
                'recipient_invalid',
            );
        }
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
