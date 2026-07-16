<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchGatewayMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class DeliveryFailureTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_sync_request_without_an_available_route_fails_without_calling_a_provider(): void
    {
        Http::fake();
        $client = $this->createClientApplication();

        $this->postMessage(
            $client['token'],
            'missing-route-1',
            $this->messagePayload('sync'),
        )
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'route_unavailable');

        $message = DB::table('gateway_messages')
            ->where('idempotency_key', 'missing-route-1')
            ->sole();

        $this->assertSame('failed', $message->status);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'failed',
        ]);
        $this->assertDatabaseMissing('message_attempts', [
            'gateway_message_id' => $message->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_policy_without_steps_fails_as_route_unavailable_without_calling_a_provider(): void
    {
        Http::fake();
        $client = $this->createClientApplication();
        $this->createRoutingPolicy($client['id'], []);

        $this->postMessage($client['token'], 'empty-route', $this->messagePayload('sync'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'route_unavailable');

        $this->assertDatabaseCount('message_attempts', 0);
        Http::assertNothingSent();
    }

    public function test_inactive_steps_and_providers_are_skipped_without_network_calls(): void
    {
        Http::fake();
        $client = $this->createClientApplication();
        $inactiveStepProvider = $this->createProviderAccount('waha', 'inactive-step-provider', [
            'base_url' => 'https://inactive-step-provider.test',
            'session' => 'default',
        ]);
        $inactiveProvider = $this->createProviderAccount('fonnte', 'inactive-provider', [
            'endpoint' => 'https://inactive-provider.test/send',
            'token' => 'secret',
        ]);
        $policyId = $this->createRoutingPolicy($client['id'], [
            $inactiveStepProvider['id'],
            $inactiveProvider['id'],
        ]);

        DB::table('routing_steps')
            ->where('routing_policy_id', $policyId)
            ->where('provider_account_id', $inactiveStepProvider['id'])
            ->update(['is_active' => false]);
        DB::table('provider_accounts')
            ->where('id', $inactiveProvider['id'])
            ->update(['is_active' => false]);

        $this->postMessage($client['token'], 'unusable-providers', $this->messagePayload('sync'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'route_unavailable');

        $this->assertSame(2, DB::table('message_attempts')->where('status', 'skipped')->count());
        Http::assertNothingSent();
    }

    public function test_unsupported_and_invalidly_configured_drivers_fail_safely_after_recording_attempts(): void
    {
        Http::fake();
        $client = $this->createClientApplication();
        $unsupported = $this->createProviderAccount('unsupported', 'unsupported-provider', []);
        $invalidWaha = $this->createProviderAccount('waha', 'invalid-waha-provider', [
            'base_url' => 'https://invalid-waha-provider.test',
        ]);
        $this->createRoutingPolicy($client['id'], [$unsupported['id'], $invalidWaha['id']]);

        $this->postMessage($client['token'], 'invalid-provider-drivers', $this->messagePayload('sync'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'providers_failed');

        $this->assertDatabaseHas('message_attempts', ['error_code' => 'provider_driver_unsupported']);
        $this->assertDatabaseHas('message_attempts', ['error_code' => 'provider_configuration_invalid']);
        Http::assertNothingSent();
    }

    public function test_unexpected_driver_exception_becomes_unknown_and_never_falls_back(): void
    {
        $requestedUrls = [];
        $client = $this->createClientApplication();
        $primary = $this->createProviderAccount('waha', 'throwing-provider', [
            'base_url' => 'https://throwing-provider.test',
            'session' => 'default',
        ]);
        $secondary = $this->createProviderAccount('fonnte', 'must-not-fallback-provider', [
            'endpoint' => 'https://must-not-fallback-provider.test/send',
            'token' => 'secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$primary['id'], $secondary['id']]);

        Http::fake(function (Request $request) use (&$requestedUrls) {
            $requestedUrls[] = $request->url();

            if (str_contains($request->url(), 'throwing-provider.test')) {
                throw new RuntimeException('Unexpected provider adapter failure.');
            }

            return Http::response(['status' => true, 'id' => 'must-not-be-called'], 200);
        });

        $this->postMessage($client['token'], 'unexpected-driver-error', $this->messagePayload('sync'))
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'provider_outcome_unknown');

        $this->assertDatabaseHas('message_attempts', [
            'status' => 'outcome_unknown',
            'error_code' => 'unexpected_driver_failure',
        ]);
        $this->assertCount(1, $requestedUrls);
        $this->assertStringContainsString('throwing-provider.test', $requestedUrls[0]);
    }

    public function test_all_definitive_provider_failures_are_recorded_before_the_message_fails(): void
    {
        $client = $this->createClientApplication();
        $primary = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-primary.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $secondary = $this->createProviderAccount('fonnte', 'fonnte-secondary', [
            'endpoint' => 'https://fonnte-secondary.test/send',
            'token' => 'secondary-secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$primary['id'], $secondary['id']]);

        Http::fake(fn (Request $request) => str_contains($request->url(), 'waha-primary.test')
            ? Http::response(['error' => 'invalid provider credential'], 401)
            : Http::response(['reason' => 'provider rate limited'], 429));

        $this->postMessage(
            $client['token'],
            'all-providers-failed-1',
            $this->messagePayload('sync'),
        )
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'providers_failed');

        $message = DB::table('gateway_messages')
            ->where('idempotency_key', 'all-providers-failed-1')
            ->sole();

        $this->assertSame('failed', $message->status);
        $this->assertSame(2, DB::table('message_attempts')
            ->where('gateway_message_id', $message->id)
            ->count());
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'failed',
        ]);
        Http::assertSentCount(2);
    }

    public function test_replaying_a_job_for_a_terminal_message_never_sends_it_twice(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-primary.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        Http::fake([
            'waha-primary.test/*' => Http::response(['id' => 'accepted-once'], 201),
        ]);

        $response = $this->postMessage(
            $client['token'],
            'terminal-job-replay-1',
            $this->messagePayload('sync'),
        )->assertCreated();

        $message = DB::table('gateway_messages')
            ->where('uuid', $response->json('data.id'))
            ->sole();

        $job = new DispatchGatewayMessage($message->id);
        $this->app->call([$job, 'handle']);
        $this->app->call([$job, 'handle']);

        $this->assertDatabaseCount('message_attempts', 1);
        Http::assertSentCount(1);
    }
}
