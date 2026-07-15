<?php

namespace Tests\Feature\Api\V1;

use App\Jobs\DispatchGatewayMessage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
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
}
