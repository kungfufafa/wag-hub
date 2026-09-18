<?php

namespace Tests\Feature\Api\Engine;

use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class CesaEngineContractTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_health_requires_a_valid_credential(): void
    {
        $this->getJson('/engine/health')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $this->getJson('/engine/t/not-a-real-token/health')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');
    }

    public function test_health_matches_the_cesa_engine_contract(): void
    {
        $client = $this->createClientApplication(['engine:use']);

        $this->withToken($client['token'])
            ->getJson('/engine/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('engine', 'wag-hub');

        $this->getJson('/engine/t/'.$client['token'].'/health')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('engine', 'wag-hub');
    }

    public function test_start_session_pairs_a_waha_device_and_returns_a_qr(): void
    {
        $client = $this->seedEngineHost();
        $this->fakeWahaQrSession('rekrutmen-12');

        $response = $this->withToken($client['token'])
            ->postJson('/engine/sessions', [
                'id' => 'rekrutmen-12',
                'mode' => 'qr',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('id', 'rekrutmen-12')
            ->assertJsonPath('status', 'qr')
            ->assertJsonPath('mode', 'qr');

        $this->assertStringStartsWith('data:image/png;base64,', (string) $response->json('qr'));

        $provider = ProviderAccount::query()->where('slug', 'web-cesa-sess-rekrutmen-12')->sole();
        $this->assertSame('waha', $provider->driver);
        $this->assertSame('rekrutmen-12', $provider->configuration['session'] ?? null);
        $this->assertSame('https://waha-engine.test', $provider->configuration['base_url'] ?? null);

        $policy = RoutingPolicy::query()
            ->where('client_application_id', $client['id'])
            ->where('operation', 'message')
            ->where('key', 'rekrutmen-12')
            ->sole();
        $this->assertTrue($policy->steps()->where('provider_account_id', $provider->id)->exists());
    }

    public function test_start_session_via_path_token_requests_a_pairing_code(): void
    {
        $client = $this->seedEngineHost();

        Http::fake([
            '*/api/sessions/rekrutmen-7/start' => Http::response(['name' => 'rekrutmen-7', 'status' => 'STARTING'], 200),
            '*/api/sessions' => Http::response(['name' => 'rekrutmen-7', 'status' => 'STARTING'], 201),
            '*/api/sessions/rekrutmen-7' => Http::response([
                'name' => 'rekrutmen-7',
                'status' => 'SCAN_QR_CODE',
            ], 200),
            '*/api/rekrutmen-7/auth/request-code' => Http::response(['code' => 'ABCD-EFGH'], 200),
        ]);

        $this->postJson('/engine/t/'.$client['token'].'/sessions', [
            'id' => 'rekrutmen-7',
            'mode' => 'pairing',
            'phone' => '081234567890',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'pairing')
            ->assertJsonPath('pairing_code', 'ABCD-EFGH')
            ->assertJsonPath('mode', 'pairing');
    }

    public function test_session_poll_and_logout_follow_the_cesa_contract(): void
    {
        $client = $this->seedEngineHost();
        $wahaStatus = 'SCAN_QR_CODE';

        Http::fake(function (Request $request) use (&$wahaStatus) {
            $url = $request->url();

            if (str_contains($url, '/auth/qr')) {
                return Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']);
            }

            if (str_contains($url, '/logout')) {
                $wahaStatus = 'STOPPED';

                return Http::response([], 200);
            }

            if (str_contains($url, '/api/sessions')) {
                return Http::response([
                    'name' => 'rekrutmen-3',
                    'status' => $wahaStatus,
                    ...($wahaStatus === 'WORKING' ? [
                        'me' => ['id' => '628111222333@c.us', 'pushName' => 'CESA HR'],
                    ] : []),
                ], str_contains($url, '/start') || $request->method() === 'POST' ? 201 : 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 599);
        });

        $this->withToken($client['token'])
            ->postJson('/engine/sessions', ['id' => 'rekrutmen-3', 'mode' => 'qr'])
            ->assertOk()
            ->assertJsonPath('status', 'qr');

        $wahaStatus = 'WORKING';

        $this->withToken($client['token'])
            ->getJson('/engine/sessions/rekrutmen-3')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('phone', '628111222333');

        $this->withToken($client['token'])
            ->deleteJson('/engine/sessions/rekrutmen-3', ['logout' => true])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'disconnected');
    }

    public function test_send_text_uses_the_session_provider_and_returns_cesa_sent_status(): void
    {
        $client = $this->seedEngineHost();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, '/api/sendText')) {
                return Http::response(['id' => 'waha-cesa-001'], 201);
            }

            if (str_contains($url, '/auth/qr')) {
                return Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']);
            }

            if (str_contains($url, '/api/sessions')) {
                return Http::response(['name' => 'rekrutmen-9', 'status' => 'SCAN_QR_CODE'], 200);
            }

            return Http::response(['error' => 'unexpected '.$url], 599);
        });

        $this->withToken($client['token'])
            ->postJson('/engine/sessions', ['id' => 'rekrutmen-9', 'mode' => 'qr'])
            ->assertOk();

        $provider = ProviderAccount::query()->where('slug', 'web-cesa-sess-rekrutmen-9')->sole();
        $provider->forceFill(['session_status' => 'working'])->save();

        $this->withToken($client['token'])
            ->postJson('/engine/sessions/rekrutmen-9/send', [
                'phone' => '081298765432',
                'text' => 'Tes undangan wawancara CESA',
                'idempotency_key' => 'invite-9-001',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('id', 'waha-cesa-001');

        $message = GatewayMessage::query()
            ->where('client_application_id', $client['id'])
            ->where('idempotency_key', 'engine:rekrutmen-9:invite-9-001')
            ->sole();

        $this->assertSame('engine', $message->origin);
        $this->assertSame('provider_accepted', $message->status);
        $this->assertSame($provider->id, $message->pinned_provider_account_id);
        $this->assertSame('rekrutmen-9', $message->route_key);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/sendText')
            && $request['session'] === 'rekrutmen-9'
            && $request['chatId'] === '6281298765432@c.us'
            && $request['text'] === 'Tes undangan wawancara CESA');

        $this->withToken($client['token'])
            ->getJson('/engine/sessions/rekrutmen-9/messages/invite-9-001')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('id', 'waha-cesa-001');

        $this->withToken($client['token'])
            ->postJson('/engine/sessions/rekrutmen-9/send', [
                'phone' => '081298765432',
                'text' => 'Tes undangan wawancara CESA',
                'idempotency_key' => 'invite-9-001',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'sent')
            ->assertJsonPath('id', 'waha-cesa-001');

        $this->assertSame(1, GatewayMessage::query()->where('client_application_id', $client['id'])->count());
    }

    public function test_hub_message_token_cannot_use_engine_and_engine_token_cannot_use_hub_api(): void
    {
        $hub = $this->createClientApplication(['messages:send', 'messages:read', 'numbers:check']);
        $engine = $this->createClientApplication(['engine:use']);

        $this->withToken($hub['token'])
            ->getJson('/engine/health')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->getJson('/engine/t/'.$hub['token'].'/health')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');

        $this->postMessage($engine['token'], 'hub-blocked-1', $this->messagePayload('sync'))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'forbidden');
    }

    public function test_unknown_message_lookup_is_a_cesa_404(): void
    {
        $client = $this->createClientApplication(['engine:use']);

        $this->withToken($client['token'])
            ->getJson('/engine/sessions/rekrutmen-1/messages/missing-key')
            ->assertNotFound()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'message_not_found');
    }

    public function test_invalid_session_id_is_rejected(): void
    {
        $client = $this->createClientApplication(['engine:use']);

        $this->withToken($client['token'])
            ->postJson('/engine/sessions', ['id' => '../etc/passwd', 'mode' => 'qr'])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'invalid_session');
    }

    public function test_send_without_a_connected_session_is_retryable(): void
    {
        $client = $this->createClientApplication(['engine:use']);

        $this->withToken($client['token'])
            ->postJson('/engine/sessions/rekrutmen-4/send', [
                'phone' => '081234567890',
                'text' => 'Halo',
                'idempotency_key' => 'send-4-001',
            ])
            ->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('retryable', true)
            ->assertJsonPath('error_code', 'not_connected');
    }

    /**
     * @return array{id: int, uuid: string, token: string, credential_id: int}
     */
    private function seedEngineHost(): array
    {
        $client = $this->createClientApplication(['engine:use']);

        $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha-engine.test',
            'session' => 'default',
            'api_key' => 'waha-engine-secret',
        ]);

        DB::table('client_applications')
            ->where('id', $client['id'])
            ->update(['slug' => 'web-cesa']);

        return $client;
    }

    private function fakeWahaQrSession(string $session): void
    {
        Http::fake([
            '*/api/sessions/'.$session.'/start' => Http::response(['name' => $session, 'status' => 'STARTING'], 200),
            '*/api/sessions' => Http::response(['name' => $session, 'status' => 'STARTING'], 201),
            '*/api/sessions/'.$session => Http::response(['name' => $session, 'status' => 'SCAN_QR_CODE'], 200),
            '*/api/'.$session.'/auth/qr*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
        ]);
    }
}
