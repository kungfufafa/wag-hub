<?php

namespace Tests\Feature\Delivery;

use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class WagHubProviderDeliveryTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'gateway.engine.baileys_url' => 'http://runner.test:3318',
            'gateway.engine.baileys_token' => 'private-runner-token',
        ]);
        Http::preventStrayRequests();
    }

    public function test_native_provider_sends_through_the_shared_ledger_and_replays_without_resending(): void
    {
        $client = $this->createClientApplication();
        $native = $this->createProviderAccount('wag_hub', 'native', ['session' => 'untrusted-session']);
        $this->createRoutingPolicy($client['id'], [$native['id']]);
        Http::fake(['runner.test:3318/*' => Http::response(['ok' => true, 'status' => 'sent', 'id' => 'native-message-1'])]);

        $payload = $this->messagePayload('sync');
        $this->postMessage($client['token'], 'order-1', $payload)->assertCreated()
            ->assertJsonPath('data.provider', 'native')->assertJsonPath('data.provider_message_id', 'native-message-1');
        $this->postMessage($client['token'], 'order-1', $payload)->assertOk()->assertJsonPath('data.duplicate', true);

        $message = GatewayMessage::query()->sole();
        $provider = ProviderAccount::findOrFail($native['id']);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://runner.test:3318/sessions/wgh-'.$provider->uuid.'/send'
            && $request['idempotency_key'] === $message->uuid
            && $request['phone'] === '6281234567890'
            && $request->hasHeader('Authorization', 'Bearer private-runner-token'));
        $this->assertDatabaseHas('message_attempts', ['gateway_message_id' => $message->id, 'provider_account_id' => $native['id'], 'status' => 'accepted']);
    }

    public function test_native_definite_failure_can_fall_back_to_fonnte(): void
    {
        $client = $this->createClientApplication();
        $native = $this->createProviderAccount('wag_hub', 'native', []);
        $fonnte = $this->createProviderAccount('fonnte', 'fonnte', ['endpoint' => 'https://fonnte.test/send', 'token' => 'secret']);
        $this->createRoutingPolicy($client['id'], [$native['id'], $fonnte['id']]);
        Http::fake([
            'runner.test:3318/*' => Http::response(['ok' => false, 'status' => 'failed', 'error_code' => 'not_connected'], 409),
            'fonnte.test/*' => Http::response(['status' => true, 'id' => ['fonnte-1']]),
        ]);

        $this->postMessage($client['token'], 'order-2', $this->messagePayload('sync'))->assertCreated()->assertJsonPath('data.provider', 'fonnte');
        Http::assertSentCount(2);
        $this->assertDatabaseCount('message_attempts', 2);
    }

    public function test_external_provider_can_fall_back_to_the_native_provider(): void
    {
        $client = $this->createClientApplication();
        $waha = $this->createProviderAccount('waha', 'waha', ['base_url' => 'https://waha.test', 'session' => 'default', 'api_key' => 'secret']);
        $native = $this->createProviderAccount('wag_hub', 'native', []);
        $this->createRoutingPolicy($client['id'], [$waha['id'], $native['id']]);
        Http::fake([
            'waha.test/*' => Http::response(['message' => 'Unauthorized'], 401),
            'runner.test:3318/*' => Http::response(['ok' => true, 'status' => 'sent', 'id' => 'native-1']),
        ]);

        $this->postMessage($client['token'], 'order-3', $this->messagePayload('sync'))->assertCreated()->assertJsonPath('data.provider', 'native');
        Http::assertSentCount(2);
    }

    public function test_uncertain_native_delivery_stops_fallback_and_late_confirmation_reconciles_without_resending(): void
    {
        $client = $this->createClientApplication();
        $native = $this->createProviderAccount('wag_hub', 'native', []);
        $fonnte = $this->createProviderAccount('fonnte', 'fonnte', ['endpoint' => 'https://fonnte.test/send', 'token' => 'secret']);
        $this->createRoutingPolicy($client['id'], [$native['id'], $fonnte['id']]);
        Http::fake([
            '*/send' => Http::failedConnection(),
            '*/messages/*' => Http::response(['ok' => true, 'status' => 'sent', 'id' => 'late-native-1']),
        ]);

        $this->postMessage($client['token'], 'order-4', $this->messagePayload('sync'))->assertStatus(502)
            ->assertJsonPath('data.status', 'outcome_unknown')->assertJsonPath('error.retryable', false);
        Http::assertSentCount(1);
        $message = GatewayMessage::query()->sole();
        $otherApp = $this->createClientApplication();
        $this->withToken($otherApp['token'])->getJson('/api/v1/messages/'.$message->uuid)->assertNotFound();
        Http::assertSentCount(1);
        $this->withToken($client['token'])->getJson('/api/v1/messages/'.$message->uuid)->assertOk()
            ->assertJsonPath('data.status', 'provider_accepted')->assertJsonPath('data.provider_message_id', 'late-native-1');
        $this->getJson('/api/v1/messages/'.$message->uuid)->assertOk();
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'fonnte.test'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/messages/'.$message->uuid));
        $this->assertDatabaseCount('message_attempts', 1);
        $this->assertDatabaseHas('message_attempts', ['gateway_message_id' => $message->id, 'status' => 'accepted']);
    }

    public function test_native_provider_participates_in_number_check_routing(): void
    {
        $client = $this->createClientApplication(['numbers:check']);
        $native = $this->createProviderAccount('wag_hub', 'native', []);
        $this->createRoutingPolicy($client['id'], [$native['id']], operation: 'number_check');
        Http::fake(['*/numbers/check' => Http::response(['ok' => true, 'registered' => false])]);

        $this->withToken($client['token'])->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ])->assertOk()->assertJsonPath('data.registered', false)->assertJsonPath('data.checks.0.provider', 'native');
        Http::assertSent(fn (Request $request): bool => $request['phone'] === '6281234567890');
    }
}
