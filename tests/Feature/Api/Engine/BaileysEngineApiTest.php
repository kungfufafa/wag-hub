<?php

namespace Tests\Feature\Api\Engine;

use App\Models\ClientApplication;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use App\Services\IntegrationPack;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class BaileysEngineApiTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'gateway.engine.driver' => 'wag_hub',
            'gateway.engine.baileys_url' => 'http://runner.test:3318',
            'gateway.engine.baileys_token' => 'private-runner-token',
        ]);
        Http::preventStrayRequests();
    }

    public function test_two_apps_using_the_same_session_id_and_message_key_are_isolated_for_every_operation(): void
    {
        $cesa = $this->createClientApplication(['engine:use']);
        $dnd = $this->createClientApplication(['engine:use']);
        $journal = [];
        Http::fake(function (Request $request) use (&$journal) {
            if (str_ends_with($request->url(), '/sessions')) {
                return Http::response(['ok' => true, 'id' => $request['id'], 'status' => 'qr', 'qr' => 'data:image/png;base64,test']);
            }
            if (str_ends_with($request->url(), '/send')) {
                $journal[dirname($request->url())] = ['ok' => true, 'status' => 'sent', 'id' => 'message-1'];

                return Http::response($journal[dirname($request->url())]);
            }
            if (str_contains($request->url(), '/messages/')) {
                $entry = $journal[dirname(dirname($request->url()))] ?? null;

                return $entry ? Http::response($entry) : Http::response(['error_code' => 'message_not_found'], 404);
            }

            return Http::response(['ok' => true, 'status' => $request->method() === 'DELETE' ? 'disconnected' : 'connected']);
        });

        foreach ([$cesa, $dnd] as $client) {
            $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', ['id' => 'hr-1', 'mode' => 'qr'])
                ->assertOk()->assertJsonPath('id', 'hr-1')->assertJsonPath('status', 'qr');
        }
        $this->withToken($cesa['token'])->getJson('/api/v1/engine/sessions/hr-1')->assertOk();
        $this->withToken($cesa['token'])->postJson('/api/v1/engine/sessions/hr-1/send', [
            'phone' => '081234567890', 'text' => 'Hello', 'idempotency_key' => 'event-1',
        ])->assertOk()->assertJsonPath('status', 'sent');
        $this->withToken($dnd['token'])->getJson('/api/v1/engine/sessions/hr-1/messages/event-1')->assertNotFound();
        $rotated = $this->createApiCredential($cesa['id'], ['engine:use']);
        $this->withToken($rotated['token'])->getJson('/api/v1/engine/sessions/hr-1/messages/event-1')
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->getJson('/api/v1/engine/sessions/hr-1')->assertOk()->assertJsonPath('id', 'hr-1');
        $this->deleteJson('/api/v1/engine/sessions/hr-1')->assertOk()->assertJsonPath('status', 'disconnected');

        $starts = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sessions'));
        $starts = $starts->values();
        $cesaId = $starts[0][0]['id'];
        $dndId = $starts[1][0]['id'];
        $this->assertNotSame($cesaId, $dndId);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/sessions/'.$cesaId) && $request['logout'] === true);
        Http::assertNotSent(fn (Request $request): bool => ! $request->hasHeader('Authorization', 'Bearer private-runner-token'));
    }

    public function test_health_probes_the_runner_and_does_not_expose_other_apps_session_counts(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        Http::fake(['*/health' => Http::response(['ok' => true, 'sessions' => 12, 'connected' => 5])]);
        $this->withToken($client['token'])->getJson('/api/v1/engine/health')
            ->assertOk()->assertJsonPath('driver', 'wag_hub')->assertJsonPath('sessions', 0)->assertJsonPath('connected', 0);

        Http::fake(['*/health' => Http::failedConnection()]);
        $this->getJson('/api/v1/engine/health')->assertStatus(503)->assertJsonPath('ok', false);
    }

    public function test_uncertain_send_is_never_retried_or_reported_as_a_definite_failure(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        Http::fake([
            '*/sessions' => Http::response(['ok' => true, 'status' => 'connected']),
            '*/send' => Http::failedConnection(),
        ]);
        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', ['id' => 'hr-1', 'mode' => 'qr'])->assertOk();
        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions/hr-1/send', [
            'phone' => '081234567890', 'text' => 'Hello', 'idempotency_key' => 'event-1',
        ])->assertOk()->assertJsonPath('status', 'unknown')->assertJsonPath('retryable', false);
        Http::assertSentCount(2);
    }

    public function test_unconfigured_or_unauthorized_requests_cannot_reach_the_runner(): void
    {
        $client = $this->createClientApplication(['messages:send']);
        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', ['id' => 'hr-1', 'mode' => 'qr'])->assertForbidden();
        $client = $this->createClientApplication(['engine:use']);
        config(['gateway.engine.baileys_token' => null]);
        $this->withToken($client['token'])->getJson('/api/v1/engine/health')->assertStatus(503);
        $this->postJson('/api/v1/engine/sessions', ['id' => 'hr-1', 'mode' => 'qr'])
            ->assertStatus(503)->assertJsonPath('error_code', 'engine_unavailable');
        Http::assertNothingSent();
    }

    public function test_integration_pack_token_can_manage_sessions_without_a_second_credential(): void
    {
        $client = $this->createClientApplication();
        $pack = app(IntegrationPack::class)->issue(ClientApplication::findOrFail($client['id']));
        Http::fake(['*/sessions' => Http::response(['ok' => true, 'status' => 'pairing', 'pairing_code' => '12345678'])]);
        $this->withToken($pack['credential']->plainTextToken)->postJson('/api/v1/engine/sessions', [
            'id' => 'support-1', 'mode' => 'pairing', 'phone' => '081234567890',
        ])->assertOk()->assertJsonPath('pairing_code', '12345678');
    }

    public function test_selected_native_session_stays_pinned_even_when_the_app_has_an_external_fallback(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        $fonnte = $this->createProviderAccount('fonnte', 'fallback', ['endpoint' => 'https://fonnte.test/send', 'token' => 'secret']);
        $this->createRoutingPolicy($client['id'], [$fonnte['id']]);
        Http::fake([
            '*/sessions' => Http::response(['ok' => true, 'status' => 'connected']),
            '*/send' => Http::response(['ok' => false, 'status' => 'failed', 'error_code' => 'not_connected'], 409),
        ]);
        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', ['id' => 'hr-1', 'mode' => 'qr'])->assertOk();
        $this->postJson('/api/v1/engine/sessions/hr-1/send', [
            'phone' => '081234567890', 'text' => 'Hello', 'idempotency_key' => 'event-1',
        ])->assertStatus(409)->assertJsonPath('status', 'failed');
        $account = ProviderAccount::query()->where('driver', 'wag_hub')->sole();
        $this->assertSame($client['id'], $account->configuration['owned_by_application_id']);
        $this->assertSame($account->id, GatewayMessage::query()->sole()->pinned_provider_account_id);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'fonnte.test'));
        $this->assertDatabaseCount('message_attempts', 1);
    }

    public function test_existing_waha_session_keeps_its_backend_after_default_changes_to_native(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        $provider = $this->createProviderAccount('waha', 'old-session', [
            'base_url' => 'https://waha.test', 'api_key' => 'secret', 'session' => 'old-upstream',
            'owned_by_application_id' => $client['id'], 'cesa_session_id' => 'hr-1',
        ]);
        Http::fake(['waha.test/*' => Http::response(['name' => 'old-upstream', 'status' => 'WORKING'])]);
        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', ['id' => 'hr-1', 'mode' => 'qr'])
            ->assertOk()->assertJsonPath('status', 'connected');
        $this->assertSame('waha', ProviderAccount::findOrFail($provider['id'])->driver);
        $this->assertDatabaseCount('provider_accounts', 1);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'runner.test'));
    }

    public function test_disconnect_without_logout_stops_the_native_socket_without_unlinking_the_phone(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        Http::fake(['runner.test:3318/*' => Http::response(['ok' => true, 'status' => 'connected'])]);
        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', ['id' => 'hr-1', 'mode' => 'qr'])->assertOk();
        $this->deleteJson('/api/v1/engine/sessions/hr-1', ['logout' => false])->assertOk()->assertJsonPath('status', 'disconnected');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request['logout'] === false);
        $this->assertFalse(ProviderAccount::query()->sole()->is_active);
    }

    public function test_existing_native_sessions_stay_available_when_the_default_for_new_sessions_changes(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        $this->createProviderAccount('wag_hub', 'app-native', [
            'owned_by_application_id' => $client['id'], 'engine_session_id' => 'hr-1',
        ]);
        config(['gateway.engine.driver' => 'waha']);
        Http::fake(['runner.test:3318/*' => Http::response(['ok' => true, 'status' => 'connected'])]);
        $this->withToken($client['token'])->getJson('/api/v1/engine/health')->assertOk()->assertJsonPath('ok', true);
        $this->getJson('/api/v1/engine/sessions/hr-1')->assertOk()->assertJsonPath('status', 'connected');
        $this->assertSame('wag_hub', ProviderAccount::query()->sole()->driver);
    }
}
