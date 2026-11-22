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
use App\Services\Engine\SessionService;
use App\Services\WhatsAppSessionManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates and (re)connects WhatsApp connections. A managed number is one v2 engine session
 * (SessionService), so it shares v2 capacity, idempotency and lifecycle; a provider route is a
 * tenant-owned provider account behind a dedicated routing policy.
 */
final class ConnectionProvisioner
{
    public function __construct(
        private SessionService $sessions,
        private WhatsAppSessionManager $providerSessions,
        private ProviderConfigurationValidator $validator,
        private ConnectionHealthProjector $health,
        private ConnectionCapabilityCatalog $capabilities,
    ) {}

    /**
     * Create a connection once per Idempotency-Key. A replay with the same key and request returns
     * the original connection; the same key with a different request is an idempotency_conflict.
     *
     * @param  array<string, mixed>  $input
     */
    public function provision(ClientApplication $application, array $input, string $idempotencyKey): ProvisionedConnection
    {
        $type = ConnectionType::from((string) ($input['type'] ?? ConnectionType::ManagedNumber->value));
        $name = trim((string) ($input['name'] ?? ''));
        $name = $name !== '' ? $name : ($type === ConnectionType::ManagedNumber ? 'WhatsApp' : 'Provider');
        $authMethod = ($input['auth_method'] ?? 'qr') === 'pairing' ? 'pairing' : 'qr';
        $phone = $this->phone($input['phone'] ?? null);
        $provider = is_array($input['provider'] ?? null) ? $input['provider'] : [];

        if ($type === ConnectionType::ManagedNumber) {
            $this->assertPairingPhone($authMethod, $phone);
        } else {
            $this->assertExternalDriver((string) ($provider['driver'] ?? ''));
        }

        $fingerprint = hash('sha256', json_encode([
            'type' => $type->value,
            'name' => $name,
            'is_default' => (bool) ($input['is_default'] ?? false),
            'auth_method' => $type === ConnectionType::ManagedNumber ? $authMethod : null,
            'phone' => $type === ConnectionType::ManagedNumber ? $phone : null,
            'driver' => $type === ConnectionType::ProviderRoute ? strtolower(trim((string) ($provider['driver'] ?? ''))) : null,
            'configuration' => $type === ConnectionType::ProviderRoute
                ? hash('sha256', json_encode($this->sorted($provider['configuration'] ?? []), JSON_THROW_ON_ERROR))
                : null,
        ], JSON_THROW_ON_ERROR));

        try {
            [$connection, $created] = $this->findOrCreate(
                $application, $idempotencyKey, $fingerprint, $type, $name,
                (bool) ($input['is_default'] ?? false), $authMethod, $phone,
            );
        } catch (UniqueConstraintViolationException) {
            // A concurrent request with the same key won the insert: answer as its replay.
            [$connection, $created] = [$this->replay($application, $idempotencyKey, $fingerprint), false];
        }

        // A provider route whose first validation failed is completed by replaying the same request.
        if ($type === ConnectionType::ProviderRoute && ($created || ! $connection->isConfigured())) {
            $this->configureProvider($connection, $provider);
        }

        return new ProvisionedConnection($this->health->refresh($connection->fresh() ?? $connection), $created);
    }

    /**
     * Start (or restart) the connection. For a managed number this issues a v2 session start
     * command, or a new session when the previous one was removed; the runner carries it out
     * asynchronously and the pending operation is reported on the connection.
     *
     * @param  array<string, mixed>  $input
     */
    public function connect(WhatsAppConnection $connection, array $input = []): WhatsAppConnection
    {
        if ($connection->isManaged()) {
            return $this->startManaged($connection, $input);
        }

        if ($connection->provider_account_id === null) {
            return $this->reconfigureProvider($connection, []);
        }

        $account = $connection->providerAccount;

        if ($account === null || ! $account->supportsSessions() || ! $connection->ownsProviderAccount($account)) {
            return $this->health->refresh($connection);
        }

        $authMethod = ($input['auth_method'] ?? 'qr') === 'pairing' ? 'pairing' : 'qr';
        $phone = $this->phone($input['phone'] ?? null);

        if ($authMethod === 'pairing') {
            $this->assertPairingPhone($authMethod, $phone);
        }

        try {
            $this->providerSessions->connect($account, $authMethod === 'pairing' ? $phone : null);
        } catch (WhatsAppEngineException $exception) {
            throw $this->engineFailure($connection, $exception);
        }

        $connection->forceFill([
            'setup_state' => array_merge($connection->setup_state ?? [], ['last_step' => 'connecting', 'auth_method' => $authMethod]),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();

        return $this->health->refresh($connection->fresh() ?? $connection);
    }

    /**
     * Retry a stalled setup. Managed numbers restart their session; provider routes re-validate
     * the stored settings merged with corrected ones (omitted keys keep their stored value).
     *
     * @param  array<string, mixed>  $configuration
     */
    public function retry(WhatsAppConnection $connection, array $configuration = []): WhatsAppConnection
    {
        if ($connection->isManaged()) {
            return $this->startManaged($connection, []);
        }

        return $this->reconfigureProvider($connection, $configuration);
    }

    /**
     * @return array{0: WhatsAppConnection, 1: bool}
     */
    private function findOrCreate(
        ClientApplication $application,
        string $key,
        string $fingerprint,
        ConnectionType $type,
        string $name,
        bool $makeDefault,
        string $authMethod,
        ?string $phone,
    ): array {
        return DB::transaction(function () use ($application, $key, $fingerprint, $type, $name, $makeDefault, $authMethod, $phone): array {
            ClientApplication::query()->whereKey($application->getKey())->lockForUpdate()->firstOrFail();

            if (WhatsAppConnection::withTrashed()
                ->where('client_application_id', $application->getKey())
                ->where('creation_key', $key)
                ->exists()) {
                return [$this->replay($application, $key, $fingerprint), false];
            }

            $sessionId = null;

            if ($type === ConnectionType::ManagedNumber) {
                // Same capacity, idempotency and start semantics as POST /api/v2/sessions; any
                // failure (capacity_exceeded, idempotency_conflict) rolls the connection back too.
                $session = $this->sessions->create($application, $key, array_filter([
                    'name' => $name,
                    'start' => true,
                    'auth_method' => $authMethod,
                    'phone' => $phone,
                ], static fn (mixed $value): bool => $value !== null));

                $linked = WhatsAppConnection::withTrashed()->where('engine_session_id', $session->getKey())->first();

                if ($linked !== null) {
                    // The key created a v2 session (POST /api/v2/sessions) that GET /connections or
                    // gateway:sync-connections already projected: adopt that projection, so the
                    // answer does not depend on whether a read happened in between.
                    return [$this->adopt($linked, $application, $key, $fingerprint, $makeDefault), true];
                }

                $sessionId = $session->getKey();
            }

            $connection = new WhatsAppConnection;
            $connection->forceFill([
                'uuid' => (string) Str::uuid(),
                'client_application_id' => $application->getKey(),
                'name' => $name,
                'type' => $type->value,
                'engine_session_id' => $sessionId,
                'creation_key' => $key,
                'creation_hash' => $fingerprint,
                'status' => $type === ConnectionType::ManagedNumber
                    ? ConnectionStatus::Connecting->value
                    : ConnectionStatus::SetupRequired->value,
                'is_default' => false,
                'capabilities' => $type === ConnectionType::ManagedNumber
                    ? $this->capabilities->forEngineSession()
                    : [],
                'setup_state' => $type === ConnectionType::ManagedNumber
                    ? ['last_step' => 'connecting', 'auth_method' => $authMethod]
                    : ['last_step' => 'created'],
            ]);
            $connection->save();

            if ($makeDefault || ! WhatsAppConnection::query()
                ->where('client_application_id', $application->getKey())
                ->where('is_default', true)
                ->whereKeyNot($connection->getKey())
                ->exists()) {
                $this->markDefault($connection);
            }

            return [$connection->fresh() ?? $connection, true];
        });
    }

    /**
     * Claim a projected connection (one without a creation key) for the session that this key
     * created. A connection already created through this API under another key is a conflict.
     */
    private function adopt(
        WhatsAppConnection $linked,
        ClientApplication $application,
        string $key,
        string $fingerprint,
        bool $makeDefault,
    ): WhatsAppConnection {
        if ($linked->creation_key !== null || (int) $linked->client_application_id !== (int) $application->getKey()
            || ! $linked->isManaged()) {
            throw new WhatsAppEngineException('This idempotency key belongs to a different request.', 409, 'idempotency_conflict');
        }

        if ($linked->trashed()) {
            throw new WhatsAppEngineException('This connection was removed. Start a new attempt.', 410, 'connection_removed');
        }

        $linked->forceFill(['creation_key' => $key, 'creation_hash' => $fingerprint])->save();

        if ($makeDefault || ! WhatsAppConnection::query()
            ->where('client_application_id', $application->getKey())
            ->where('is_default', true)
            ->whereKeyNot($linked->getKey())
            ->exists()) {
            $this->markDefault($linked);
        }

        return $linked->fresh() ?? $linked;
    }

    private function replay(ClientApplication $application, string $key, string $fingerprint): WhatsAppConnection
    {
        $existing = WhatsAppConnection::withTrashed()
            ->where('client_application_id', $application->getKey())
            ->where('creation_key', $key)
            ->firstOrFail();

        if (! hash_equals((string) $existing->creation_hash, $fingerprint)) {
            throw new WhatsAppEngineException('This idempotency key belongs to a different request.', 409, 'idempotency_conflict');
        }

        if ($existing->trashed()) {
            throw new WhatsAppEngineException('This connection was removed. Start a new attempt.', 410, 'connection_removed');
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function startManaged(WhatsAppConnection $connection, array $input): WhatsAppConnection
    {
        $requested = $input['auth_method'] ?? null;
        $phone = $this->phone($input['phone'] ?? null);

        if ($requested === 'pairing') {
            $this->assertPairingPhone('pairing', $phone);
        }

        // Pairing needs a fresh phone each time; without one a restart falls back to QR.
        $authMethod = $requested === 'pairing' ? 'pairing' : 'qr';

        DB::transaction(function () use ($connection, $authMethod, $phone): void {
            $locked = WhatsAppConnection::query()->whereKey($connection->getKey())->lockForUpdate()->firstOrFail();
            $session = $locked->ownedEngineSession();
            $payload = array_filter(['mode' => $authMethod, 'phone' => $authMethod === 'pairing' ? $phone : null]);

            if ($session === null || $session->trashed()) {
                // The previous session was removed: a new one is a new v2 session and uses capacity.
                $session = $this->sessions->create($locked->clientApplication, 'connection:'.$locked->uuid.':'.Str::lower(Str::random(16)), array_filter([
                    'name' => $locked->name,
                    'start' => true,
                    'auth_method' => $authMethod,
                    'phone' => $payload['phone'] ?? null,
                ], static fn (mixed $value): bool => $value !== null));
            } else {
                $this->sessions->command($session, 'start', $payload);
            }

            $setup = array_merge($locked->setup_state ?? [], ['last_step' => 'connecting', 'auth_method' => $authMethod]);

            if ($locked->engine_session_id !== null && (int) $locked->engine_session_id !== (int) $session->getKey()) {
                // Remember the replaced session so its accepted messages still replay by key.
                $setup['previous_engine_session_ids'] = array_values(array_unique([
                    ...array_map('intval', (array) ($setup['previous_engine_session_ids'] ?? [])),
                    (int) $locked->engine_session_id,
                ]));
            }

            $locked->forceFill([
                'engine_session_id' => $session->getKey(),
                'setup_state' => $setup,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
        });

        return $this->health->refresh($connection->fresh() ?? $connection);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function reconfigureProvider(WhatsAppConnection $connection, array $configuration): WhatsAppConnection
    {
        $account = $connection->providerAccount;

        if ($account !== null && ! $connection->ownsProviderAccount($account)) {
            if ($configuration !== []) {
                // Rejected before any write so a refused request never stores an error on a shared connection.
                throw new WhatsAppConnectionException(
                    'This connection uses a provider account managed by the administrator.',
                    409,
                    'capability_not_supported',
                );
            }

            // Shared, admin-managed accounts and their routes are never rewritten or re-enabled from here.
            return $this->health->refresh($connection);
        }

        $this->configureProvider($connection, [
            // A first validation failure leaves no account, so the requested driver lives in setup_state.
            'driver' => $account?->driver ?? ($connection->setup_state['driver'] ?? null),
            'configuration' => $configuration,
        ]);

        return $this->health->refresh($connection->fresh() ?? $connection);
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function configureProvider(WhatsAppConnection $connection, array $provider): void
    {
        try {
            $this->provisionProvider($connection, $provider);
        } catch (WhatsAppConnectionException $exception) {
            $this->markRecoverableFailure($connection, $exception->errorCode, $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $provider
     */
    private function provisionProvider(WhatsAppConnection $connection, array $provider): void
    {
        $driver = strtolower(trim((string) ($provider['driver'] ?? $connection->providerAccount?->driver ?? '')));
        $configuration = is_array($provider['configuration'] ?? null) ? $provider['configuration'] : [];
        $this->assertExternalDriver($driver);
        $account = $connection->providerAccount;

        if ($driver !== '') {
            // Remember the requested driver so retry can re-run validation before an account exists.
            $connection->forceFill([
                'setup_state' => array_merge($connection->setup_state ?? [], ['driver' => $driver]),
            ])->save();
        }

        $existing = $account?->configuration ?? [];
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
        // Only the account this connection created is ever updated; is_active stays as an admin left it.
        $account = $connection->providerAccount ?? ProviderAccount::query()
            ->where('slug', $this->accountSlug($connection))
            ->first();

        if ($account !== null) {
            $account->forceFill([
                'driver' => $driver,
                'configuration' => array_merge($account->configuration ?? [], $configuration, [
                    'whatsapp_connection_id' => $connection->getKey(),
                ]),
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
            // A disabled step stays disabled: only an administrator re-enables routing.
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
        return $connection->providerAccountSlug();
    }

    /**
     * The native WAG Hub number is a managed_number connection, never a tenant provider account.
     */
    private function assertExternalDriver(string $driver): void
    {
        if (strtolower(trim($driver)) === 'wag_hub') {
            throw new WhatsAppConnectionException(
                'Use a managed_number connection for the built-in WAG Hub number.',
                422,
                'capability_not_supported',
            );
        }
    }

    private function assertPairingPhone(string $authMethod, ?string $phone): void
    {
        if ($authMethod === 'pairing' && ($phone === null || $phone === '')) {
            throw new WhatsAppConnectionException(
                'Pairing requires a phone number.',
                422,
                'recipient_invalid',
            );
        }
    }

    private function phone(mixed $value): ?string
    {
        $phone = is_string($value) ? trim($value) : '';

        return $phone === '' ? null : $phone;
    }

    private function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item): mixed => $this->sorted($item), $value);
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

    /**
     * Runner outages are transient: report them as a retryable not-ready connection, never as bad credentials.
     */
    private function engineFailure(WhatsAppConnection $connection, WhatsAppEngineException $exception): WhatsAppConnectionException
    {
        $unavailable = in_array(
            $exception->errorCode,
            ['engine_unavailable', 'engine_unconfigured', 'engine_unreachable'],
            true,
        );
        $code = $unavailable ? 'connection_not_ready' : $exception->errorCode;
        $retryable = $unavailable || $exception->retryable;

        if ($unavailable || $exception->errorCode !== 'operation_pending') {
            $this->markRecoverableFailure($connection, $code, $exception->getMessage());
        }

        return new WhatsAppConnectionException(
            $exception->getMessage(),
            $exception->statusCode,
            $code,
            $retryable,
        );
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
                'recommended_action' => match ($code) {
                    'connection_not_ready' => 'Start WAG Hub runner',
                    'provider_configuration_invalid', 'provider_endpoint_not_allowed' => 'Fix provider settings',
                    default => 'Fix credentials',
                },
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
