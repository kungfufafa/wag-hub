<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait BuildsGatewayFixtures
{
    /**
     * @param  list<string>  $abilities
     * @return array{id: int, uuid: string, token: string, credential_id: int}
     */
    protected function createClientApplication(
        array $abilities = ['messages:send', 'messages:read'],
        bool $active = true,
        bool $revoked = false,
    ): array {
        $suffix = Str::lower(Str::random(10));
        $uuid = (string) Str::uuid();
        $now = now();

        $applicationId = DB::table('client_applications')->insertGetId([
            'uuid' => $uuid,
            'name' => "Test Client {$suffix}",
            'slug' => "test-client-{$suffix}",
            'is_active' => $active,
            'rate_limit_per_minute' => 60,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $credential = $this->createApiCredential($applicationId, $abilities, $revoked);

        return [
            'id' => $applicationId,
            'uuid' => $uuid,
            'token' => $credential['token'],
            'credential_id' => $credential['id'],
        ];
    }

    /**
     * @param  list<string>  $abilities
     * @return array{id: int, token: string}
     */
    protected function createApiCredential(int $applicationId, array $abilities, bool $revoked = false): array
    {
        $token = 'wgh_test_'.Str::random(40);
        $now = now();

        $credentialId = DB::table('api_credentials')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $applicationId,
            'name' => 'Test credential '.Str::lower(Str::random(8)),
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 16),
            'abilities' => Crypt::encryptString(json_encode(array_values($abilities), JSON_THROW_ON_ERROR)),
            'last_used_at' => null,
            'expires_at' => null,
            'revoked_at' => $revoked ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $credentialId, 'token' => $token];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{id: int, slug: string}
     */
    protected function createProviderAccount(string $driver, string $slug, array $configuration): array
    {
        $now = now();

        $id = DB::table('provider_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'name' => Str::headline($slug),
            'slug' => $slug,
            'driver' => $driver,
            'configuration' => Crypt::encryptString(json_encode($configuration, JSON_THROW_ON_ERROR)),
            'is_active' => true,
            'health_status' => 'healthy',
            'consecutive_failures' => 0,
            'circuit_open_until' => null,
            'timeout_seconds' => 15,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => $id, 'slug' => $slug];
    }

    /**
     * @param  list<int>  $providerAccountIds
     */
    protected function createRoutingPolicy(
        int $applicationId,
        array $providerAccountIds,
        string $purpose = 'notification',
        string $routeKey = 'default',
    ): int {
        $now = now();
        $policyId = DB::table('routing_policies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $applicationId,
            'name' => 'Test route '.Str::lower(Str::random(8)),
            'key' => $routeKey,
            'purpose' => $purpose,
            'is_default' => true,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($providerAccountIds as $index => $providerAccountId) {
            DB::table('routing_steps')->insert([
                'routing_policy_id' => $policyId,
                'provider_account_id' => $providerAccountId,
                'position' => $index + 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $policyId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function messagePayload(string $mode = 'async', array $overrides = []): array
    {
        return array_replace_recursive([
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'message' => ['type' => 'text', 'text' => 'Pesan pengujian gateway'],
            'purpose' => 'notification',
            'mode' => $mode,
            'route_key' => 'default',
            'client_reference' => 'test-ref-'.Str::lower(Str::random(8)),
            'metadata' => ['entity_type' => 'test_notification'],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postMessage(?string $token, ?string $idempotencyKey, array $payload)
    {
        $headers = ['Accept' => 'application/json'];

        if ($token !== null) {
            $headers['Authorization'] = "Bearer {$token}";
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $this->withHeaders($headers)->postJson('/api/v1/messages', $payload);
    }
}
