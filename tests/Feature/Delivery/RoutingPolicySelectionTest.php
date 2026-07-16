<?php

namespace Tests\Feature\Delivery;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class RoutingPolicySelectionTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_soft_deleted_policy_is_never_selected_over_an_active_fallback_policy(): void
    {
        $client = $this->createClientApplication();
        $deletedProvider = $this->createProviderAccount('waha', 'deleted-route-provider', [
            'base_url' => 'https://deleted-route-provider.test',
            'api_key' => 'deleted-secret',
            'session' => 'default',
        ]);
        $activeProvider = $this->createProviderAccount('fonnte', 'active-route-provider', [
            'endpoint' => 'https://active-route-provider.test/send',
            'token' => 'active-secret',
        ]);

        $deletedPolicy = $this->insertPolicy($client['id'], 'notification', true, now());
        $activePolicy = $this->insertPolicy($client['id'], null, true);
        $this->insertStep($deletedPolicy, $deletedProvider['id']);
        $this->insertStep($activePolicy, $activeProvider['id']);

        Http::fake(fn (Request $request) => str_contains($request->url(), 'deleted-route-provider.test')
            ? Http::response(['id' => 'must-not-use-deleted-route'], 201)
            : Http::response(['status' => true, 'id' => 'active-route-accepted'], 200));

        $response = $this->postMessage(
            $client['token'],
            'ignore-soft-deleted-policy',
            $this->messagePayload('sync'),
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.provider', 'active-route-provider');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->url(),
            'active-route-provider.test',
        ));
    }

    public function test_equally_specific_policies_are_selected_by_lowest_id_deterministically(): void
    {
        $client = $this->createClientApplication();
        $firstProvider = $this->createProviderAccount('waha', 'first-deterministic-provider', [
            'base_url' => 'https://first-deterministic-provider.test',
            'api_key' => 'first-secret',
            'session' => 'default',
        ]);
        $secondProvider = $this->createProviderAccount('waha', 'second-deterministic-provider', [
            'base_url' => 'https://second-deterministic-provider.test',
            'api_key' => 'second-secret',
            'session' => 'default',
        ]);

        $firstPolicy = $this->insertPolicy($client['id'], null, true);
        $secondPolicy = $this->insertPolicy($client['id'], null, true);
        $this->insertStep($firstPolicy, $firstProvider['id']);
        $this->insertStep($secondPolicy, $secondProvider['id']);

        Http::fake(fn (Request $request) => Http::response([
            'id' => str_contains($request->url(), 'first-deterministic-provider.test')
                ? 'first-selected'
                : 'second-selected',
        ], 201));

        $this->postMessage(
            $client['token'],
            'deterministic-policy-selection',
            $this->messagePayload('sync'),
        )
            ->assertCreated()
            ->assertJsonPath('data.provider', 'first-deterministic-provider');

        Http::assertSentCount(1);
    }

    public function test_purpose_specific_policy_wins_over_a_generic_policy(): void
    {
        $client = $this->createClientApplication();
        $genericProvider = $this->createProviderAccount('waha', 'generic-purpose-provider', [
            'base_url' => 'https://generic-purpose-provider.test',
            'session' => 'default',
        ]);
        $specificProvider = $this->createProviderAccount('fonnte', 'specific-purpose-provider', [
            'endpoint' => 'https://specific-purpose-provider.test/send',
            'token' => 'secret',
        ]);
        $genericPolicy = $this->insertPolicy($client['id'], null, false);
        $specificPolicy = $this->insertPolicy($client['id'], 'notification', false);
        $this->insertStep($genericPolicy, $genericProvider['id']);
        $this->insertStep($specificPolicy, $specificProvider['id']);

        Http::fake(fn (Request $request) => str_contains($request->url(), 'specific-purpose-provider.test')
            ? Http::response(['status' => true, 'id' => 'specific-selected'], 200)
            : Http::response(['id' => 'generic-must-not-win'], 201));

        $this->postMessage(
            $client['token'],
            'purpose-specific-selection',
            $this->messagePayload('sync'),
        )
            ->assertCreated()
            ->assertJsonPath('data.provider', 'specific-purpose-provider');

        Http::assertSentCount(1);
    }

    public function test_client_default_policy_wins_before_a_global_exact_route(): void
    {
        $client = $this->createClientApplication();
        $clientProvider = $this->createProviderAccount('waha', 'client-default-provider', [
            'base_url' => 'https://client-default-provider.test',
            'session' => 'default',
        ]);
        $globalProvider = $this->createProviderAccount('fonnte', 'global-exact-provider', [
            'endpoint' => 'https://global-exact-provider.test/send',
            'token' => 'secret',
        ]);
        $clientPolicy = $this->insertPolicy($client['id'], null, true, key: 'default');
        $globalPolicy = $this->insertPolicy(null, null, false, key: 'campaign');
        $this->insertStep($clientPolicy, $clientProvider['id']);
        $this->insertStep($globalPolicy, $globalProvider['id']);

        Http::fake(fn (Request $request) => str_contains($request->url(), 'client-default-provider.test')
            ? Http::response(['id' => 'client-default-selected'], 201)
            : Http::response(['status' => true, 'id' => 'global-must-not-win'], 200));

        $this->postMessage(
            $client['token'],
            'client-default-before-global',
            $this->messagePayload('sync', ['route_key' => 'campaign']),
        )
            ->assertCreated()
            ->assertJsonPath('data.provider', 'client-default-provider');

        Http::assertSentCount(1);
    }

    public function test_global_policy_is_used_only_when_the_client_has_no_matching_route(): void
    {
        $client = $this->createClientApplication();
        $globalProvider = $this->createProviderAccount('waha', 'global-fallback-provider', [
            'base_url' => 'https://global-fallback-provider.test',
            'session' => 'default',
        ]);
        $globalPolicy = $this->insertPolicy(null, 'notification', true);
        $this->insertStep($globalPolicy, $globalProvider['id']);

        Http::fake(['*' => Http::response(['id' => 'global-selected'], 201)]);

        $this->postMessage(
            $client['token'],
            'global-fallback-selection',
            $this->messagePayload('sync'),
        )
            ->assertCreated()
            ->assertJsonPath('data.provider', 'global-fallback-provider');

        Http::assertSentCount(1);
    }

    private function insertPolicy(
        ?int $applicationId,
        ?string $purpose,
        bool $default,
        mixed $deletedAt = null,
        string $key = 'default',
    ): int {
        $now = now();

        return DB::table('routing_policies')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $applicationId,
            'name' => 'Policy '.Str::random(8),
            'key' => $key,
            'purpose' => $purpose,
            'is_default' => $default,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => $deletedAt,
        ]);
    }

    private function insertStep(int $policyId, int $providerId): void
    {
        DB::table('routing_steps')->insert([
            'routing_policy_id' => $policyId,
            'provider_account_id' => $providerId,
            'position' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
