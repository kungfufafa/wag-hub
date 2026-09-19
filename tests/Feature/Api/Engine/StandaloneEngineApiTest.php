<?php

namespace Tests\Feature\Api\Engine;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class StandaloneEngineApiTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['gateway.engine.driver' => 'waha']);
    }

    public function test_versioned_api_requires_an_engine_credential_and_reports_configuration_readiness(): void
    {
        $this->getJson('/api/v1/engine/health')->assertUnauthorized();
        $hub = $this->createClientApplication(['messages:send']);
        $this->withToken($hub['token'])->getJson('/api/v1/engine/health')->assertForbidden();

        $client = $this->createClientApplication(['engine:use']);
        $this->withToken($client['token'])->getJson('/api/v1/engine/health')
            ->assertStatus(503)->assertJsonPath('ok', false)->assertJsonPath('waha_ready', false);

        $this->createHost();
        $this->getJson('/api/v1/engine/health')
            ->assertOk()->assertJsonPath('ok', true)->assertJsonPath('api_version', 'v1')
            ->assertJsonPath('capabilities.0', 'sessions:qr');
    }

    public function test_legacy_path_token_does_not_replace_the_session_or_message_route_parameters(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        $this->createHost();
        $this->fakeConnectedEngine();
        $base = '/engine/t/'.$client['token'];

        $this->postJson($base.'/sessions', ['id' => 'support-1', 'mode' => 'qr'])
            ->assertOk()->assertJsonPath('id', 'support-1');
        $this->getJson($base.'/sessions/support-1')->assertOk()->assertJsonPath('id', 'support-1');
        $this->postJson($base.'/sessions/support-1/send', [
            'phone' => '081234567890', 'text' => 'Halo', 'idempotency_key' => 'event-1',
        ])->assertOk()->assertJsonPath('status', 'sent');
        $this->getJson($base.'/sessions/support-1/messages/event-1')
            ->assertOk()->assertJsonPath('status', 'sent');
        $this->deleteJson($base.'/sessions/support-1')->assertOk()->assertJsonPath('status', 'disconnected');
    }

    public function test_versioned_send_accepts_a_header_idempotency_key_and_replays_without_resending(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        $this->createHost();
        $this->fakeConnectedEngine();

        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', ['id' => 'support-1', 'mode' => 'qr'])
            ->assertOk();

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->postJson('/api/v1/engine/sessions/support-1/send', [
                'phone' => '081234567890', 'text' => 'Halo',
            ], ['Idempotency-Key' => 'event-1'])->assertOk()->assertJsonPath('status', 'sent');
        }

        $sends = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/sendText'));
        $this->assertCount(1, $sends);
    }

    public function test_invalid_payload_types_return_contract_errors_without_contacting_the_provider(): void
    {
        $client = $this->createClientApplication(['engine:use']);
        Http::fake();
        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', ['id' => ['support-1'], 'mode' => 'qr'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'invalid_session');
        $this->postJson('/api/v1/engine/sessions', ['id' => 'support-1', 'mode' => 'pairing', 'phone' => ['123']])
            ->assertUnprocessable()->assertJsonPath('error_code', 'invalid_phone');
        $this->postJson('/api/v1/engine/sessions/support-1/send', [
            'phone' => '081234567890', 'text' => ['invalid'], 'idempotency_key' => 'event-1',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'invalid_text');
        $this->deleteJson('/api/v1/engine/sessions/support-1', ['logout' => 'false'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'invalid_logout');
        Http::assertNothingSent();
    }

    public function test_revoked_credentials_cannot_read_sessions(): void
    {
        $client = $this->createClientApplication(['engine:use'], revoked: true);
        $this->withToken($client['token'])->getJson('/api/v1/engine/sessions/support-1')->assertUnauthorized();
    }

    private function createHost(): void
    {
        $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://engine.test', 'api_key' => 'engine-secret', 'session' => 'default',
        ]);
    }

    private function fakeConnectedEngine(): void
    {
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/api/sendText')) {
                return Http::response(['id' => 'provider-message-1'], 201);
            }

            if (str_ends_with($request->url(), '/logout')) {
                return Http::response(['status' => 'STOPPED']);
            }

            return Http::response(['status' => 'WORKING', 'me' => ['id' => '628111222333@c.us']]);
        });
    }
}
