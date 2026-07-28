<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchGatewayMessage;
use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use App\Support\PayloadHasher;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class MessageIngressTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_post_requires_a_valid_active_bearer_credential_without_creating_messages(): void
    {
        Queue::fake();
        $payload = $this->messagePayload();

        $this->postMessage(null, 'missing-token', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->postMessage('not-a-real-token', 'invalid-token', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $inactive = $this->createClientApplication(active: false);
        $this->postMessage($inactive['token'], 'inactive-client', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $revoked = $this->createClientApplication(revoked: true);
        $this->postMessage($revoked['token'], 'revoked-token', $payload)
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_expired_credential_is_rejected_without_creating_or_queueing_a_message(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();

        DB::table('api_credentials')
            ->where('id', $client['credential_id'])
            ->update(['expires_at' => now()->subSecond()]);

        $this->postMessage($client['token'], 'expired-credential', $this->messagePayload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_malformed_credential_expiry_fails_closed_without_an_internal_error(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();

        DB::table('api_credentials')
            ->where('id', $client['credential_id'])
            ->update(['expires_at' => 'not-a-valid-date']);

        $this->postMessage($client['token'], 'malformed-expiry', $this->messagePayload())
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_corrupted_credential_abilities_fail_closed_without_creating_work(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();

        DB::table('api_credentials')
            ->where('id', $client['credential_id'])
            ->update(['abilities' => 'corrupted-encrypted-value']);

        $this->postMessage($client['token'], 'corrupted-abilities', $this->messagePayload())
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_post_requires_the_messages_send_ability(): void
    {
        Queue::fake();
        $client = $this->createClientApplication(['messages:read']);

        $this->postMessage($client['token'], 'send-forbidden', $this->messagePayload())
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_idempotency_key_is_required_and_bounded_before_an_async_message_is_created(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();
        $payload = $this->messagePayload();

        $this->postMessage($client['token'], null, $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->postMessage($client['token'], str_repeat('k', 161), $payload)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertDatabaseCount('gateway_messages', 0);
        Queue::assertNothingPushed();

        $this->postMessage($client['token'], 'valid-key-after-validation', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseCount('gateway_messages', 1);
        Queue::assertPushed(DispatchGatewayMessage::class, 1);
    }

    public function test_async_request_commits_a_queued_message_event_and_one_job(): void
    {
        Queue::fake();
        Http::fake();
        $client = $this->createClientApplication();
        $correlationId = (string) Str::uuid();

        $response = $this
            ->withHeader('X-Correlation-ID', $correlationId)
            ->postMessage($client['token'], 'async-ledger-1', $this->messagePayload());

        $response
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.mode', 'async')
            ->assertJsonPath('data.duplicate', false)
            ->assertJsonPath('request_id', $correlationId)
            ->assertJsonStructure([
                'data' => ['id', 'status', 'mode', 'duplicate', 'created_at'],
                'request_id',
            ]);

        $messageId = $response->json('data.id');
        $this->assertTrue(Str::isUuid($messageId));

        $message = DB::table('gateway_messages')->where('uuid', $messageId)->first();
        $this->assertNotNull($message);
        $this->assertSame($client['id'], $message->client_application_id);
        $this->assertSame('async-ledger-1', $message->idempotency_key);
        $this->assertSame($correlationId, $message->correlation_id);
        $this->assertSame('queued', $message->status);
        $this->assertSame('async', $message->mode);
        $this->assertNotNull($message->queued_at);

        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->id,
            'type' => 'queued',
            'source' => 'api',
        ]);
        $this->assertDatabaseCount('message_attempts', 0);
        Queue::assertPushed(DispatchGatewayMessage::class, 1);
        Http::assertNothingSent();
    }

    public function test_an_otp_expiry_with_a_timezone_offset_is_stored_in_the_application_timezone(): void
    {
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
        $client = $this->createClientApplication();
        $expiresAt = now()->addMinute();

        $response = $this->postMessage(
            $client['token'],
            'otp-expiry-timezone',
            $this->messagePayload('async', [
                'purpose' => 'otp',
                'expires_at' => $expiresAt->toIso8601String(),
            ]),
        )->assertStatus(202);

        $message = DB::table('gateway_messages')->where('uuid', $response->json('data.id'))->sole();

        $this->assertSame($expiresAt->toDateTimeString(), $message->expires_at);
        $this->travelBack();
    }

    public function test_an_identical_replay_returns_the_same_resource_without_new_work(): void
    {
        Queue::fake();
        Http::fake();
        $client = $this->createClientApplication();
        $payload = $this->messagePayload();

        $first = $this->postMessage($client['token'], 'same-request-key', $payload)
            ->assertStatus(202)
            ->assertJsonPath('data.duplicate', false);

        $replay = $this->postMessage($client['token'], 'same-request-key', $payload)
            ->assertStatus(200)
            ->assertJsonPath('data.duplicate', true);

        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertDatabaseCount('gateway_messages', 1);
        $this->assertDatabaseCount('message_events', 1);
        $this->assertDatabaseCount('message_attempts', 0);
        Queue::assertPushed(DispatchGatewayMessage::class, 1);
        Http::assertNothingSent();
    }

    public function test_concurrent_idempotent_insert_returns_the_winning_message_without_duplicate_work(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();
        $payload = $this->messagePayload();
        $winner = null;
        $armed = true;

        DB::listen(function (QueryExecuted $query) use (&$armed, &$winner, $client, $payload): void {
            if (
                ! $armed
                || ! str_starts_with(strtolower(ltrim($query->sql)), 'select')
                || ! str_contains(strtolower($query->sql), 'gateway_messages')
            ) {
                return;
            }

            $armed = false;
            $canonicalRecipient = app(PhoneNormalizer::class)->normalize($payload['recipient']['value']);
            $canonicalPayload = [
                'recipient' => ['type' => 'phone', 'value' => $canonicalRecipient],
                'message' => ['type' => 'text', 'text' => $payload['message']['text']],
                'purpose' => $payload['purpose'],
                'mode' => $payload['mode'],
                'route_key' => $payload['route_key'],
                'expires_at' => null,
                'client_reference' => $payload['client_reference'],
                'metadata' => $payload['metadata'],
            ];

            $winner = GatewayMessage::query()->forceCreate([
                'uuid' => (string) Str::uuid(),
                'client_application_id' => $client['id'],
                'idempotency_key' => 'concurrent-key',
                'payload_hash' => app(PayloadHasher::class)->hash($canonicalPayload, (string) config('app.key')),
                'correlation_id' => (string) Str::uuid(),
                'client_reference' => $payload['client_reference'],
                'recipient' => $canonicalRecipient,
                'recipient_hash' => hash_hmac('sha256', $canonicalRecipient, (string) config('app.key')),
                'recipient_last4' => substr($canonicalRecipient, -4),
                'body' => $payload['message']['text'],
                'purpose' => $payload['purpose'],
                'route_key' => $payload['route_key'],
                'mode' => 'async',
                'priority' => 10,
                'status' => 'queued',
                'metadata' => $payload['metadata'],
                'queued_at' => now(),
            ]);

            MessageEvent::query()->forceCreate([
                'gateway_message_id' => $winner->getKey(),
                'type' => 'queued',
                'source' => 'api',
                'occurred_at' => now(),
            ]);
        });

        $response = $this->postMessage($client['token'], 'concurrent-key', $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $this->assertNotNull($winner);
        $this->assertSame($winner->uuid, $response->json('data.id'));
        $this->assertDatabaseCount('gateway_messages', 1);
        $this->assertDatabaseCount('message_events', 1);
        Queue::assertNothingPushed();
    }

    public function test_same_application_key_with_a_different_payload_conflicts(): void
    {
        Queue::fake();
        $client = $this->createClientApplication();
        $payload = $this->messagePayload();

        $first = $this->postMessage($client['token'], 'payload-conflict-key', $payload)
            ->assertStatus(202);

        $changed = $payload;
        $changed['message']['text'] = 'Isi yang berbeda dan tidak boleh dikirim';

        $conflict = $this->postMessage($client['token'], 'payload-conflict-key', $changed)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');

        $this->assertStringContainsString($first->json('data.id'), $conflict->getContent());
        $this->assertDatabaseCount('gateway_messages', 1);
        Queue::assertPushed(DispatchGatewayMessage::class, 1);
    }

    public function test_the_same_idempotency_key_is_independent_between_applications(): void
    {
        Queue::fake();
        $firstClient = $this->createClientApplication();
        $secondClient = $this->createClientApplication();
        $payload = $this->messagePayload();

        $first = $this->postMessage($firstClient['token'], 'shared-across-apps', $payload)
            ->assertStatus(202);
        $second = $this->postMessage($secondClient['token'], 'shared-across-apps', $payload)
            ->assertStatus(202);

        $this->assertNotSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('gateway_messages', 2);
        $this->assertSame(2, DB::table('gateway_messages')
            ->where('idempotency_key', 'shared-across-apps')
            ->distinct()
            ->count('client_application_id'));
        Queue::assertPushed(DispatchGatewayMessage::class, 2);
    }

    public function test_status_reads_are_limited_to_the_owning_application_and_read_ability(): void
    {
        Queue::fake();
        $owner = $this->createClientApplication();
        $other = $this->createClientApplication();
        $sendOnly = $this->createApiCredential($owner['id'], ['messages:send']);

        $created = $this->postMessage($owner['token'], 'owned-message', $this->messagePayload())
            ->assertStatus(202);
        $messageId = $created->json('data.id');

        $ownerResponse = $this->withToken($owner['token'])
            ->getJson("/api/v1/messages/{$messageId}")
            ->assertOk()
            ->assertJsonPath('data.id', $messageId)
            ->assertJsonPath('data.status', 'queued');

        $safeData = $ownerResponse->json('data');
        $this->assertArrayNotHasKey('body', $safeData);
        $this->assertArrayNotHasKey('recipient', $safeData);
        $this->assertArrayNotHasKey('metadata', $safeData);

        $otherResponse = $this->withToken($other['token'])
            ->getJson("/api/v1/messages/{$messageId}");
        $this->assertContains($otherResponse->status(), [403, 404]);
        $this->assertStringNotContainsString($messageId, $otherResponse->getContent());

        $this->withToken($sendOnly['token'])
            ->getJson("/api/v1/messages/{$messageId}")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_async_mode_with_global_sync_dispatch_returns_accepted_after_inline_send(): void
    {
        config(['gateway.dispatch' => 'sync']);

        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-global-sync-accept', [
            'base_url' => 'https://waha-global-sync-accept.test',
            'api_key' => 'waha-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        Http::fake([
            'waha-global-sync-accept.test/*' => Http::response(['id' => 'waha-inline-001'], 201),
        ]);

        $this->postMessage($client['token'], 'async-global-sync-ok', $this->messagePayload())
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'provider_accepted')
            ->assertJsonPath('data.mode', 'async')
            ->assertJsonPath('data.provider_message_id', 'waha-inline-001')
            ->assertJsonPath('data.duplicate', false);

        $this->assertDatabaseHas('gateway_messages', [
            'idempotency_key' => 'async-global-sync-ok',
            'status' => 'provider_accepted',
            'mode' => 'async',
        ]);
        $this->assertDatabaseMissing('message_events', [
            'type' => 'enqueue_failed',
        ]);
    }

    public function test_async_mode_with_global_sync_dispatch_surfaces_provider_failure(): void
    {
        config(['gateway.dispatch' => 'sync']);

        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-global-sync-fail', [
            'base_url' => 'https://waha-global-sync-fail.test',
            'api_key' => 'waha-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        Http::fake([
            'waha-global-sync-fail.test/*' => Http::response(['error' => 'rejected'], 401),
        ]);

        $this->postMessage($client['token'], 'async-global-sync-fail', $this->messagePayload())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'providers_failed')
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.mode', 'async');

        $this->assertDatabaseHas('gateway_messages', [
            'idempotency_key' => 'async-global-sync-fail',
            'status' => 'failed',
            'mode' => 'async',
        ]);
        $this->assertDatabaseMissing('message_events', [
            'type' => 'enqueue_failed',
        ]);
    }

    public function test_gateway_dispatch_config_defaults_to_async_when_env_unset(): void
    {
        $previousEnv = $_ENV['GATEWAY_DISPATCH'] ?? null;
        $previousServer = $_SERVER['GATEWAY_DISPATCH'] ?? null;

        unset($_ENV['GATEWAY_DISPATCH'], $_SERVER['GATEWAY_DISPATCH']);
        putenv('GATEWAY_DISPATCH');

        try {
            $config = require config_path('gateway.php');
            $this->assertSame('async', $config['dispatch']);
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['GATEWAY_DISPATCH']);
            } else {
                $_ENV['GATEWAY_DISPATCH'] = $previousEnv;
            }

            if ($previousServer === null) {
                unset($_SERVER['GATEWAY_DISPATCH']);
            } else {
                $_SERVER['GATEWAY_DISPATCH'] = $previousServer;
            }

            if ($previousEnv !== null) {
                putenv('GATEWAY_DISPATCH='.$previousEnv);
            } else {
                putenv('GATEWAY_DISPATCH');
            }
        }
    }

    public function test_async_mode_with_global_sync_dispatch_fails_when_inline_job_leaves_message_queued(): void
    {
        config(['gateway.dispatch' => 'sync']);

        $client = $this->createClientApplication();
        $this->createRoutingPolicy(
            $client['id'],
            [$this->createProviderAccount('waha', 'waha-global-sync-noop', [
                'base_url' => 'https://waha-global-sync-noop.test',
                'api_key' => 'waha-secret',
                'session' => 'default',
            ])['id']],
        );

        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatchSync')
            ->once()
            ->with(Mockery::type(DispatchGatewayMessage::class))
            ->andReturnNull();
        $this->app->instance(BusDispatcher::class, $bus);

        $this->postMessage($client['token'], 'async-global-sync-noop', $this->messagePayload())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'queue_unavailable')
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.mode', 'async');

        $message = GatewayMessage::query()->where('idempotency_key', 'async-global-sync-noop')->sole();
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->getKey(),
            'type' => 'enqueue_failed',
            'source' => 'api',
        ]);
    }

    public function test_async_mode_with_global_sync_dispatch_records_enqueue_failed_on_handoff_throw(): void
    {
        config(['gateway.dispatch' => 'sync']);

        $client = $this->createClientApplication();
        $this->createRoutingPolicy(
            $client['id'],
            [$this->createProviderAccount('waha', 'waha-global-sync-throw', [
                'base_url' => 'https://waha-global-sync-throw.test',
                'api_key' => 'waha-secret',
                'session' => 'default',
            ])['id']],
        );

        $bus = Mockery::mock(BusDispatcher::class);
        $bus->shouldReceive('dispatchSync')
            ->once()
            ->with(Mockery::type(DispatchGatewayMessage::class))
            ->andThrow(new RuntimeException('Inline dispatch unavailable.'));
        $this->app->instance(BusDispatcher::class, $bus);

        $this->postMessage($client['token'], 'async-global-sync-throw', $this->messagePayload())
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'queue_unavailable')
            ->assertJsonPath('data.status', 'queued');

        $message = GatewayMessage::query()->where('idempotency_key', 'async-global-sync-throw')->sole();
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->getKey(),
            'type' => 'enqueue_failed',
            'source' => 'api',
        ]);
    }

    public function test_idempotent_replay_recovers_enqueue_failure_under_global_sync_dispatch(): void
    {
        config(['gateway.dispatch' => 'sync']);

        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waha', 'waha-global-sync-recover', [
            'base_url' => 'https://waha-global-sync-recover.test',
            'api_key' => 'waha-secret',
            'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);

        $payload = $this->messagePayload();
        $canonicalRecipient = app(PhoneNormalizer::class)->normalize($payload['recipient']['value']);
        $canonicalPayload = [
            'recipient' => ['type' => 'phone', 'value' => $canonicalRecipient],
            'message' => ['type' => 'text', 'text' => $payload['message']['text']],
            'purpose' => $payload['purpose'],
            'mode' => $payload['mode'],
            'route_key' => $payload['route_key'],
            'expires_at' => null,
            'client_reference' => $payload['client_reference'],
            'metadata' => $payload['metadata'],
        ];

        $message = GatewayMessage::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'client_application_id' => $client['id'],
            'idempotency_key' => 'async-global-sync-recover',
            'payload_hash' => app(PayloadHasher::class)->hash($canonicalPayload, (string) config('app.key')),
            'correlation_id' => (string) Str::uuid(),
            'client_reference' => $payload['client_reference'],
            'recipient' => $canonicalRecipient,
            'recipient_hash' => hash_hmac('sha256', $canonicalRecipient, (string) config('app.key')),
            'recipient_last4' => substr($canonicalRecipient, -4),
            'body' => $payload['message']['text'],
            'purpose' => $payload['purpose'],
            'route_key' => $payload['route_key'],
            'mode' => 'async',
            'priority' => 10,
            'status' => 'queued',
            'metadata' => $payload['metadata'],
            'queued_at' => now(),
        ]);

        MessageEvent::query()->forceCreate([
            'gateway_message_id' => $message->getKey(),
            'type' => 'queued',
            'source' => 'api',
            'occurred_at' => now(),
        ]);
        MessageEvent::query()->forceCreate([
            'gateway_message_id' => $message->getKey(),
            'type' => 'enqueue_failed',
            'source' => 'api',
            'occurred_at' => now(),
        ]);

        Http::fake([
            'waha-global-sync-recover.test/*' => Http::response(['id' => 'waha-recovered-001'], 201),
        ]);

        $this->postMessage($client['token'], 'async-global-sync-recover', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'provider_accepted')
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.provider_message_id', 'waha-recovered-001');

        $message->refresh();
        $this->assertSame('provider_accepted', $message->status);
        $this->assertDatabaseHas('message_events', [
            'gateway_message_id' => $message->getKey(),
            'type' => 'enqueue_recovered',
            'source' => 'api',
        ]);
    }
}
