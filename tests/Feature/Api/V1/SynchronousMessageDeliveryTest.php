<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchGatewayMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class SynchronousMessageDeliveryTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_sync_acceptance_is_returned_only_after_attempt_and_events_are_persisted(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-primary.test',
            'api_key' => 'waha-test-secret',
            'session' => 'default',
        ]);
        $policyId = $this->createRoutingPolicy($client['id'], [$provider['id']]);

        Http::fake([
            'waha-primary.test/*' => Http::response(['id' => 'waha-remote-001'], 201),
        ]);

        $payload = $this->messagePayload('sync');
        $response = $this->postMessage($client['token'], 'sync-accepted-1', $payload);

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'provider_accepted')
            ->assertJsonPath('data.mode', 'sync')
            ->assertJsonPath('data.provider', 'waha-primary')
            ->assertJsonPath('data.provider_message_id', 'waha-remote-001')
            ->assertJsonPath('data.duplicate', false);

        $message = DB::table('gateway_messages')->where('uuid', $response->json('data.id'))->first();
        $this->assertNotNull($message);
        $this->assertSame('provider_accepted', $message->status);
        $this->assertSame($policyId, $message->routing_policy_id);
        $this->assertSame($provider['id'], $message->accepted_provider_account_id);
        $this->assertSame('waha-remote-001', $message->provider_message_id);
        $this->assertNotNull($message->provider_accepted_at);

        $attempt = DB::table('message_attempts')->where('gateway_message_id', $message->id)->sole();
        $this->assertSame($provider['id'], $attempt->provider_account_id);
        $this->assertSame(1, $attempt->sequence);
        $this->assertSame('accepted', $attempt->status);
        $this->assertSame('accepted', $attempt->delivery_certainty);
        $this->assertSame(201, $attempt->http_status);
        $this->assertSame('waha-remote-001', $attempt->provider_message_id);
        $this->assertNotNull($attempt->latency_ms);
        $this->assertNotNull($attempt->started_at);
        $this->assertNotNull($attempt->finished_at);

        $eventTypes = DB::table('message_events')
            ->where('gateway_message_id', $message->id)
            ->pluck('type')
            ->all();
        $this->assertContains('processing', $eventTypes);
        $this->assertContains('attempt_started', $eventTypes);
        $this->assertContains('provider_accepted', $eventTypes);

        Queue::assertNothingPushed();
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-Api-Key', 'waha-test-secret')
            && $request['session'] === 'default'
            && $request['chatId'] === '6281234567890@c.us'
            && $request['text'] === $payload['message']['text']
        );

        $this->assertStringNotContainsString($payload['message']['text'], $response->getContent());
        $this->assertStringNotContainsString('waha-test-secret', $response->getContent());
    }

    public function test_sync_delivery_falls_back_after_a_definitive_provider_failure_and_keeps_ordered_attempts(): void
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

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'waha-primary.test')) {
                return Http::response(['error' => 'temporarily unavailable'], 503);
            }

            return Http::response([
                'status' => true,
                'id' => 'fonnte-remote-002',
                'process' => 'pending',
            ], 200);
        });

        $response = $this->postMessage(
            $client['token'],
            'sync-fallback-1',
            $this->messagePayload('sync'),
        );

        $response
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'provider_accepted')
            ->assertJsonPath('data.provider', 'fonnte-secondary')
            ->assertJsonPath('data.provider_message_id', 'fonnte-remote-002');

        $message = DB::table('gateway_messages')->where('uuid', $response->json('data.id'))->first();
        $this->assertSame('provider_accepted', $message->status);
        $this->assertSame($secondary['id'], $message->accepted_provider_account_id);

        $attempts = DB::table('message_attempts')
            ->where('gateway_message_id', $message->id)
            ->orderBy('sequence')
            ->get();
        $this->assertCount(2, $attempts);

        $this->assertSame($primary['id'], $attempts[0]->provider_account_id);
        $this->assertSame(1, $attempts[0]->sequence);
        $this->assertSame('provider_failed', $attempts[0]->status);
        $this->assertSame('not_sent', $attempts[0]->delivery_certainty);
        $this->assertSame('fallback_allowed', $attempts[0]->retry_disposition);
        $this->assertSame(503, $attempts[0]->http_status);
        $this->assertNotNull($attempts[0]->latency_ms);

        $this->assertSame($secondary['id'], $attempts[1]->provider_account_id);
        $this->assertSame(2, $attempts[1]->sequence);
        $this->assertSame('accepted', $attempts[1]->status);
        $this->assertSame('accepted', $attempts[1]->delivery_certainty);
        $this->assertSame(200, $attempts[1]->http_status);
        $this->assertSame('fonnte-remote-002', $attempts[1]->provider_message_id);

        $events = DB::table('message_events')
            ->where('gateway_message_id', $message->id)
            ->pluck('type')
            ->all();
        $this->assertContains('fallback_started', $events);
        $this->assertContains('provider_accepted', $events);
        $this->assertNotContains('failed', $events);
        Http::assertSentCount(2);
    }

    public function test_an_ambiguous_provider_response_becomes_outcome_unknown_without_fallback(): void
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

        Http::fake([
            'waha-primary.test/*' => Http::response('not-json-from-provider', 200, [
                'Content-Type' => 'text/plain',
            ]),
            '*' => Http::response(['status' => true, 'id' => 'must-not-send'], 200),
        ]);

        $response = $this->postMessage(
            $client['token'],
            'sync-unknown-1',
            $this->messagePayload('sync'),
        );

        $response
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'provider_outcome_unknown');

        $message = DB::table('gateway_messages')->where('idempotency_key', 'sync-unknown-1')->sole();
        $this->assertSame('outcome_unknown', $message->status);
        $this->assertNotNull($message->outcome_unknown_at);
        $this->assertNull($message->accepted_provider_account_id);

        $attempt = DB::table('message_attempts')->where('gateway_message_id', $message->id)->sole();
        $this->assertSame($primary['id'], $attempt->provider_account_id);
        $this->assertSame('outcome_unknown', $attempt->status);
        $this->assertSame('unknown', $attempt->delivery_certainty);
        $this->assertSame('reconcile_only', $attempt->retry_disposition);
        $this->assertSame(200, $attempt->http_status);

        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'outcome_unknown',
        ]);
        $this->assertDatabaseMissing('message_attempts', [
            'gateway_message_id' => $message->id,
            'provider_account_id' => $secondary['id'],
        ]);
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('not-json-from-provider', $response->getContent());
    }

    public function test_a_message_that_expires_in_the_queue_is_never_sent_to_a_provider(): void
    {
        Queue::fake();
        Http::fake();
        $this->travelTo(now()->startOfSecond());

        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-primary.test',
            'api_key' => 'primary-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $response = $this->postMessage(
            $client['token'],
            'async-expires-1',
            $this->messagePayload('async', ['expires_at' => now()->addMinute()->toIso8601String()]),
        )->assertStatus(202);

        $message = DB::table('gateway_messages')->where('uuid', $response->json('data.id'))->sole();
        $this->travel(2)->minutes();

        $job = new DispatchGatewayMessage($message->id);
        $this->app->call([$job, 'handle']);

        $expired = DB::table('gateway_messages')->where('id', $message->id)->sole();
        $this->assertSame('expired', $expired->status);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'expired',
        ]);
        $this->assertDatabaseMissing('message_attempts', [
            'gateway_message_id' => $message->id,
        ]);
        Http::assertNothingSent();
        Queue::assertPushed(DispatchGatewayMessage::class, 1);

        $this->travelBack();
    }
}
