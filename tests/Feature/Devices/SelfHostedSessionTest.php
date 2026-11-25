<?php

namespace Tests\Feature\Devices;

use App\Models\InboxMessage;
use App\Models\ProviderAccount;
use App\Services\WhatsAppSessionManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class SelfHostedSessionTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_connecting_marks_session_waiting_for_qr(): void
    {
        $provider = $this->wahaProvider();

        Http::fake([
            '*/api/sessions/default/start' => Http::response(['name' => 'default', 'status' => 'STARTING'], 200),
            '*/api/sessions/default' => Http::response(['name' => 'default', 'status' => 'SCAN_QR_CODE', 'config' => [
                'noweb' => ['store' => ['enabled' => true]],
                'webhooks' => [
                    ['url' => 'https://crm.example.com/hook', 'events' => ['message']],
                    ['url' => url('/webhooks/whatsapp/'.$provider->uuid), 'events' => ['message']],
                ],
            ]], 200),
            '*/api/sessions' => Http::response(['name' => 'default', 'status' => 'STARTING'], 201),
        ]);

        $status = app(WhatsAppSessionManager::class)->connect($provider);

        $this->assertSame('scan_qr', $status->value);
        $this->assertSame('scan_qr', $provider->fresh()->session_status);
        $this->assertNotNull($provider->fresh()->session_synced_at);

        $secret = $provider->fresh()->configuration['webhook_secret'];
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/sessions/default')
            && $request['config']['noweb'] === ['store' => ['enabled' => true]]
            && count($request['config']['webhooks']) === 2
            && $request['config']['webhooks'][0]['url'] === 'https://crm.example.com/hook'
            && $request['config']['webhooks'][1]['customHeaders'] === [['name' => 'X-Webhook-Token', 'value' => $secret]]);
    }

    public function test_qr_is_returned_as_a_data_uri(): void
    {
        $provider = $this->wahaProvider();

        Http::fake([
            '*/api/default/auth/qr*' => Http::response(base64_decode(self::PNG), 200, ['Content-Type' => 'image/png']),
        ]);

        $qr = app(WhatsAppSessionManager::class)->qrDataUri($provider);

        $this->assertIsString($qr);
        $this->assertStringStartsWith('data:image/png;base64,', $qr);
    }

    public function test_disconnect_logs_the_device_out(): void
    {
        $provider = $this->wahaProvider();
        $provider->forceFill(['session_status' => 'working'])->save();

        Http::fake([
            '*/api/sessions/default/logout' => Http::response([], 200),
            '*/api/sessions/default' => Http::response(['name' => 'default', 'status' => 'STOPPED'], 200),
        ]);

        $status = app(WhatsAppSessionManager::class)->disconnect($provider);

        $this->assertSame('stopped', $status->value);
        $this->assertSame('stopped', $provider->fresh()->session_status);
    }

    public function test_session_status_webhook_updates_pairing_state_without_creating_messages(): void
    {
        $provider = $this->wahaProvider();

        $this->withHeader('X-Webhook-Token', 'hook-token')->postJson('/webhooks/whatsapp/'.$provider->uuid, [
            'event' => 'session.status',
            'payload' => [
                'name' => 'default',
                'status' => 'WORKING',
                'me' => ['id' => '628123456789@c.us', 'pushName' => 'Bisnis Demo'],
            ],
        ])->assertOk()->assertJson(['ok' => true, 'session' => 'working']);

        $fresh = $provider->fresh();
        $this->assertSame('working', $fresh->session_status);
        $this->assertSame('628123456789@c.us', $fresh->session_meta['phone'] ?? null);
        $this->assertSame(0, InboxMessage::query()->count());
    }

    private function wahaProvider(): ProviderAccount
    {
        return ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-engine', [
                'base_url' => 'https://waha-engine.test',
                'session' => 'default',
                'api_key' => 'secret-key',
                'webhook_secret' => 'hook-token',
            ])['id'],
        );
    }
}
