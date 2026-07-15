<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchGatewayMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
