<?php

namespace Tests\Feature\Delivery;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class AdditionalProviderDeliveryTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_gowa_sends_text_using_basic_auth_and_device_scope(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('gowa', 'gowa-primary', [
            'base_url' => 'https://gowa-primary.test',
            'username' => 'gateway',
            'password' => 'gowa-secret',
            'device_id' => 'device-main',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake([
            'gowa-primary.test/*' => Http::response([
                'code' => 'SUCCESS',
                'message' => 'Success',
                'results' => ['message_id' => 'gowa-remote-1', 'status' => 'sent'],
            ]),
        ]);

        $this->postMessage($client['token'], 'gowa-send-1', $this->messagePayload('sync'))
            ->assertCreated()
            ->assertJsonPath('data.provider', 'gowa-primary')
            ->assertJsonPath('data.provider_message_id', 'gowa-remote-1');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gowa-primary.test/send/message'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('gateway:gowa-secret'))
            && $request->hasHeader('X-Device-Id', 'device-main')
            && $request['phone'] === '6281234567890@s.whatsapp.net'
            && $request['message'] === 'Pesan pengujian gateway');
    }

    public function test_waba_sends_text_using_meta_cloud_api_contract(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('waba', 'waba-primary', [
            'base_url' => 'https://graph-meta.test',
            'api_version' => 'v25.0',
            'phone_number_id' => '123456789012345',
            'access_token' => 'meta-system-user-token',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']]);
        Http::fake([
            'graph-meta.test/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['wa_id' => '6281234567890']],
                'messages' => [['id' => 'wamid.123']],
            ]),
        ]);

        $this->postMessage($client['token'], 'waba-send-1', $this->messagePayload('sync'))
            ->assertCreated()
            ->assertJsonPath('data.provider', 'waba-primary')
            ->assertJsonPath('data.provider_message_id', 'wamid.123');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://graph-meta.test/v25.0/123456789012345/messages'
            && $request->hasHeader('Authorization', 'Bearer meta-system-user-token')
            && $request['messaging_product'] === 'whatsapp'
            && $request['recipient_type'] === 'individual'
            && $request['to'] === '6281234567890'
            && $request['type'] === 'text'
            && $request['text'] === ['preview_url' => false, 'body' => 'Pesan pengujian gateway']);
    }
}
