<?php

namespace Tests\Feature\Delivery;

use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ProviderCircuitBreakerTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('gateway.provider_health.failure_threshold', 3);
        config()->set('gateway.provider_health.circuit_open_seconds', 300);
    }

    public function test_three_definitive_failures_open_the_primary_circuit_and_the_next_message_skips_it(): void
    {
        $client = $this->createClientApplication();
        $primary = $this->createProviderAccount('waha', 'waha-circuit-primary', [
            'base_url' => 'https://waha-circuit-primary.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $secondary = $this->createProviderAccount('fonnte', 'fonnte-circuit-secondary', [
            'endpoint' => 'https://fonnte-circuit-secondary.test/send',
            'token' => 'secondary-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$primary['id'], $secondary['id']]);

        $primaryCalls = 0;
        $secondaryCalls = 0;
        Http::fake(function (Request $request) use (&$primaryCalls, &$secondaryCalls) {
            if (str_contains($request->url(), 'waha-circuit-primary.test')) {
                $primaryCalls++;

                throw new ConnectionException('cURL error 7: Failed to connect to provider');
            }

            $secondaryCalls++;

            return Http::response([
                'status' => true,
                'id' => 'secondary-'.$secondaryCalls,
            ]);
        });

        foreach (range(1, 3) as $sequence) {
            $this->postMessage(
                $client['token'],
                "circuit-failure-{$sequence}",
                $this->messagePayload('sync'),
            )->assertCreated();
        }

        $openedPrimary = ProviderAccount::query()->findOrFail($primary['id']);
        $this->assertSame(3, $openedPrimary->consecutive_failures);
        $this->assertSame('unavailable', $openedPrimary->health_status);
        $this->assertTrue($openedPrimary->circuit_open_until->isFuture());

        $fourth = $this->postMessage(
            $client['token'],
            'circuit-skips-open-primary',
            $this->messagePayload('sync'),
        )->assertCreated();

        $this->assertSame(3, $primaryCalls);
        $this->assertSame(4, $secondaryCalls);

        $fourthMessageId = DB::table('gateway_messages')
            ->where('uuid', $fourth->json('data.id'))
            ->value('id');
        $this->assertDatabaseHas('message_attempts', [
            'gateway_message_id' => $fourthMessageId,
            'provider_account_id' => $primary['id'],
            'status' => 'skipped',
        ]);
    }

    public function test_an_accepted_result_resets_provider_health_and_closes_an_expired_circuit(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-health-reset', [
            'base_url' => 'https://waha-health-reset.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        ProviderAccount::query()->whereKey($provider['id'])->update([
            'consecutive_failures' => 2,
            'health_status' => 'degraded',
            'circuit_open_until' => now()->subMinute(),
        ]);

        Http::fake([
            'waha-health-reset.test/*' => Http::response(['id' => 'health-reset-accepted'], 201),
        ]);

        $this->postMessage(
            $client['token'],
            'accepted-resets-provider-health',
            $this->messagePayload('sync'),
        )->assertCreated();

        $healthyProvider = ProviderAccount::query()->findOrFail($provider['id']);
        $this->assertSame(0, $healthyProvider->consecutive_failures);
        $this->assertSame('healthy', $healthyProvider->health_status);
        $this->assertNull($healthyProvider->circuit_open_until);
    }

    public function test_unknown_outcomes_open_the_circuit_without_falling_back_for_the_current_message(): void
    {
        $client = $this->createClientApplication();
        $primary = $this->createProviderAccount('waha', 'waha-unknown-primary', [
            'base_url' => 'https://waha-unknown-primary.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $secondary = $this->createProviderAccount('fonnte', 'fonnte-unknown-secondary', [
            'endpoint' => 'https://fonnte-unknown-secondary.test/send',
            'token' => 'secondary-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$primary['id'], $secondary['id']]);

        $primaryCalls = 0;
        $secondaryCalls = 0;
        Http::fake(function (Request $request) use (&$primaryCalls, &$secondaryCalls) {
            if (str_contains($request->url(), 'waha-unknown-primary.test')) {
                $primaryCalls++;

                return Http::response(['message' => 'Upstream result is uncertain.'], 500);
            }

            $secondaryCalls++;

            return Http::response([
                'status' => true,
                'id' => 'secondary-after-open',
            ]);
        });

        foreach (range(1, 3) as $sequence) {
            $this->postMessage(
                $client['token'],
                "unknown-circuit-{$sequence}",
                $this->messagePayload('sync'),
            )
                ->assertStatus(502)
                ->assertJsonPath('error.code', 'provider_outcome_unknown');
        }

        $openedPrimary = ProviderAccount::query()->findOrFail($primary['id']);
        $this->assertSame(3, $primaryCalls);
        $this->assertSame(0, $secondaryCalls);
        $this->assertSame(3, $openedPrimary->consecutive_failures);
        $this->assertSame('unavailable', $openedPrimary->health_status);
        $this->assertTrue($openedPrimary->circuit_open_until->isFuture());

        $fourth = $this->postMessage(
            $client['token'],
            'unknown-circuit-skips-open-primary',
            $this->messagePayload('sync'),
        )->assertCreated();

        $this->assertSame(3, $primaryCalls);
        $this->assertSame(1, $secondaryCalls);

        $fourthMessageId = DB::table('gateway_messages')
            ->where('uuid', $fourth->json('data.id'))
            ->value('id');
        $this->assertDatabaseHas('message_attempts', [
            'gateway_message_id' => $fourthMessageId,
            'provider_account_id' => $primary['id'],
            'status' => 'skipped',
        ]);
    }
}
