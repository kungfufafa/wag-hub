<?php

namespace Tests\Feature\Api\V1;

use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ConnectionApiTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_can_create_managed_number_connection(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read', 'engine:use']);

        $this->withToken($client['token'])
            ->postJson('/api/v1/connections', [
                'name' => 'WhatsApp Utama',
                'type' => 'managed_number',
                'is_default' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'managed_number')
            ->assertJsonPath('data.status', 'setup_required')
            ->assertJsonPath('data.is_default', true);

        $this->assertDatabaseCount('whatsapp_connections', 1);
    }

    public function test_can_create_provider_route_connection_with_auto_provisioning(): void
    {
        config()->set('gateway.provider_endpoints.https_hosts', ['fonnte.test']);
        $client = $this->createClientApplication(['messages:send', 'messages:read', 'engine:use']);

        $this->withToken($client['token'])
            ->postJson('/api/v1/connections', [
                'name' => 'Fonnte Shelf',
                'type' => 'provider_route',
                'driver' => 'fonnte',
                'configuration' => [
                    'endpoint' => 'https://fonnte.test/send',
                    'token' => 'secret-token',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'provider_route');

        $connection = WhatsAppConnection::query()->sole();
        $this->assertNotNull($connection->provider_account_id);
        $this->assertNotNull($connection->routing_policy_id);
    }

    public function test_post_messages_with_connection_id_uses_connection_path(): void
    {
        Http::fake([
            'https://fonnte.test/*' => Http::response(['status' => true, 'id' => 'prov-1'], 200),
        ]);

        config()->set('gateway.provider_endpoints.https_hosts', ['fonnte.test']);
        config()->set('gateway.dispatch', 'sync');

        $client = $this->createClientApplication(['messages:send', 'messages:read', 'engine:use']);

        $connectionId = $this->withToken($client['token'])
            ->postJson('/api/v1/connections', [
                'name' => 'Fonnte Test',
                'type' => 'provider_route',
                'driver' => 'fonnte',
                'configuration' => [
                    'endpoint' => 'https://fonnte.test/send',
                    'token' => 'secret-token',
                ],
            ])
            ->json('data.id');

        $this->withToken($client['token'])
            ->withHeader('Idempotency-Key', 'conn-msg-1')
            ->postJson('/api/v1/messages', [
                'connection_id' => $connectionId,
                'recipient' => ['type' => 'phone', 'value' => '6281234567890'],
                'message' => ['type' => 'text', 'text' => 'Hello connection'],
                'purpose' => 'notification',
                'mode' => 'sync',
            ])
            ->assertCreated()
            ->assertJsonPath('data.connection_id', $connectionId);
    }

    public function test_legacy_messages_without_connection_id_do_not_set_connection(): void
    {
        $client = $this->createClientApplication();
        $provider = $this->createProviderAccount('fonnte', 'fonnte-legacy', [
            'endpoint' => 'https://fonnte.test/send',
            'token' => 'secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']], 'notification', 'default');

        $this->withToken($client['token'])
            ->withHeader('Idempotency-Key', 'legacy-1')
            ->postJson('/api/v1/messages', [
                'recipient' => ['type' => 'phone', 'value' => '6281234567890'],
                'message' => ['type' => 'text', 'text' => 'Hello'],
                'purpose' => 'notification',
                'mode' => 'async',
                'route_key' => 'default',
            ])
            ->assertAccepted()
            ->assertJsonMissingPath('data.connection_id');

        $this->assertNull(\App\Models\GatewayMessage::query()->value('whatsapp_connection_id'));
    }
}
