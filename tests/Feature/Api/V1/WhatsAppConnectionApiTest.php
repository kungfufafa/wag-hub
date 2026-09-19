<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class WhatsAppConnectionApiTest extends TestCase
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
            'gateway.dispatch' => 'sync',
        ]);
        Http::preventStrayRequests();
    }

    public function test_managed_number_onboarding_provisions_internal_resources_without_manual_routes(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        Http::fake([
            '*/sessions' => Http::response(['ok' => true, 'id' => 'wa-test', 'status' => 'qr', 'qr' => 'data:image/png;base64,test']),
            '*/sessions/*' => Http::response(['ok' => true, 'status' => 'qr', 'qr' => 'data:image/png;base64,test']),
        ]);

        $response = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => ConnectionType::ManagedNumber->value,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Support')
            ->assertJsonPath('data.type', 'managed_number')
            ->assertJsonPath('data.status', ConnectionStatus::Connecting->value)
            ->assertJsonPath('data.recommended_action', 'Scan QR')
            ->assertJsonMissingPath('data.configuration')
            ->assertJsonMissingPath('data.token');

        $this->assertSame(1, WhatsAppConnection::query()->count());
        $this->assertSame(1, ProviderAccount::query()->count());
        $this->assertSame(1, RoutingPolicy::query()->where('operation', 'message')->count());

        $retry = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => ConnectionType::ManagedNumber->value,
        ]);

        $retry->assertOk()->assertJsonPath('data.id', $response->json('data.id'));
        $this->assertSame(1, WhatsAppConnection::query()->count());
        $this->assertSame(1, ProviderAccount::query()->count());
    }

    public function test_unconfigured_engine_leaves_a_recoverable_setup_required_connection(): void
    {
        config(['gateway.engine.baileys_token' => null]);
        $client = $this->createClientApplication(['messages:send', 'messages:read']);

        $failed = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => 'managed_number',
        ]);
        $failed->assertStatus(503)->assertJsonPath('error.code', 'connection_not_ready');
        $this->assertNotEmpty($failed->json('error.audit_id'));

        $connection = WhatsAppConnection::query()->sole();
        $this->assertSame(ConnectionStatus::SetupRequired->value, $connection->status);
        $this->assertSame('Start WAG Hub runner', $connection->recommended_action);
        $this->assertSame(0, ProviderAccount::query()->count());

        config(['gateway.engine.baileys_token' => 'private-runner-token']);
        Http::fake(['*' => Http::response(['ok' => true, 'status' => 'qr', 'qr' => 'data:image/png;base64,test'])]);

        $this->withToken($client['token'])->postJson('/api/v1/connections/'.$connection->uuid.'/retry')
            ->assertOk()
            ->assertJsonPath('data.status', ConnectionStatus::Connecting->value);

        $this->assertSame(1, ProviderAccount::query()->count());
        $this->assertSame(1, WhatsAppConnection::query()->count());
    }

    public function test_provider_route_creates_a_safe_default_delivery_strategy(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);

        $response = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Fonnte',
            'type' => ConnectionType::ProviderRoute->value,
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['token' => 'fonnte-secret'],
            ],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.type', 'provider_route')
            ->assertJsonPath('data.status', ConnectionStatus::SetupRequired->value)
            ->assertJsonPath('data.recommended_action', 'Send a test message')
            ->assertJsonPath('data.sender.driver', 'fonnte')
            ->assertJsonPath('data.health.health', 'unknown')
            ->assertJsonMissingPath('data.sender.token');

        $json = json_encode($response->json());
        $this->assertIsString($json);
        $this->assertStringNotContainsString('fonnte-secret', $json);

        $policy = RoutingPolicy::query()->where('operation', 'message')->sole();
        $this->assertTrue((bool) $policy->is_default);
        $this->assertSame('default', $policy->key);
        $this->assertSame(1, $policy->steps()->count());
        $this->assertSame(1, RoutingPolicy::query()->where('operation', 'number_check')->count());
    }

    public function test_default_connection_send_stays_pinned_to_the_managed_number(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        $fallback = $this->createProviderAccount('fonnte', 'fonnte-fallback', [
            'endpoint' => 'https://api.fonnte.com/send',
            'token' => 'fallback-token',
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'runner.test')) {
                if (str_ends_with($request->url(), '/send')) {
                    return Http::response(['ok' => true, 'status' => 'sent', 'id' => 'wa-1']);
                }

                return Http::response(['ok' => true, 'status' => 'connected', 'phone' => '6281111111111']);
            }

            return Http::response(['status' => true], 200);
        });

        $created = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => 'managed_number',
        ])->assertCreated();

        $connection = WhatsAppConnection::query()->where('uuid', $created->json('data.id'))->firstOrFail();
        $this->createRoutingPolicy($client['id'], [$fallback['id'], $connection->provider_account_id]);

        $send = $this->withHeaders([
            'Authorization' => 'Bearer '.$client['token'],
            'Idempotency-Key' => 'welcome-1',
        ])->postJson('/api/v1/messages', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'message' => ['type' => 'text', 'text' => 'Halo dari koneksi'],
        ]);

        $send->assertCreated()
            ->assertJsonPath('data.status', 'provider_accepted')
            ->assertJsonPath('data.connection_id', $connection->uuid);

        $message = DB::table('gateway_messages')->where('uuid', $send->json('data.id'))->first();
        $this->assertSame($connection->id, $message->whatsapp_connection_id);
        $this->assertSame($connection->provider_account_id, $message->pinned_provider_account_id);
        $this->assertSame($connection->provider_account_id, $message->accepted_provider_account_id);
        $this->assertSame(1, DB::table('message_attempts')->where('gateway_message_id', $message->id)->count());
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.fonnte.com'));
    }

    public function test_routed_connection_may_use_configured_fallback(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        config([
            'gateway.provider_endpoints.https_hosts' => array_values(array_unique([
                ...config('gateway.provider_endpoints.https_hosts', []),
                'primary.waha.test',
            ])),
        ]);
        Http::fake([
            'https://primary.waha.test/*' => Http::response(['error' => 'rejected'], 400),
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => 'fonnte-1']),
        ]);

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Primary',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'waha',
                'configuration' => [
                    'base_url' => 'https://primary.waha.test',
                    'session' => 'default',
                    'api_key' => 'waha-key',
                ],
            ],
        ])->assertCreated();

        $connection = WhatsAppConnection::query()->firstOrFail();
        $fallback = $this->createProviderAccount('fonnte', 'fonnte-secondary', [
            'endpoint' => 'https://api.fonnte.com/send',
            'token' => 'fonnte-token',
        ]);
        DB::table('routing_steps')->insert([
            'routing_policy_id' => $connection->routing_policy_id,
            'provider_account_id' => $fallback['id'],
            'position' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$client['token'],
            'Idempotency-Key' => 'route-1',
        ])->postJson('/api/v1/messages', [
            'connection_id' => $connection->uuid,
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'message' => ['type' => 'text', 'text' => 'Fallback ok'],
        ])->assertCreated()->assertJsonPath('data.provider', 'fonnte-secondary');

        $this->assertSame(2, DB::table('message_attempts')->count());
    }

    public function test_legacy_message_payloads_keep_existing_error_codes(): void
    {
        $client = $this->createClientApplication();

        $this->postMessage($client['token'], 'no-route', $this->messagePayload('sync'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'route_unavailable')
            ->assertJsonPath('error.action', 'connection_not_ready');
    }

    public function test_applications_are_rejected_for_unsupported_capabilities(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        Http::fake(['*' => Http::response(['ok' => true, 'status' => 'connected'])]);

        $created = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => 'managed_number',
        ])->assertCreated();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$client['token'],
            'Idempotency-Key' => 'image-1',
        ])->postJson('/api/v1/messages', [
            'connection_id' => $created->json('data.id'),
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'message' => [
                'type' => 'image',
                'attachment' => ['url' => 'https://cdn.example.com/photo.jpg'],
            ],
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'capability_not_supported');
    }

    public function test_existing_engine_sessions_are_projected_as_connections(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read', 'engine:use']);
        Http::fake(['*' => Http::response(['ok' => true, 'status' => 'connected', 'phone' => '6281234567890'])]);

        $this->withToken($client['token'])->postJson('/api/v1/engine/sessions', [
            'id' => 'hr-1',
            'mode' => 'qr',
        ])->assertOk();

        $this->withToken($client['token'])->getJson('/api/v1/connections')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'managed_number')
            ->assertJsonPath('data.0.is_default', true);

        $this->assertSame(1, WhatsAppConnection::query()->count());
        $application = ClientApplication::query()->findOrFail($client['id']);
        $this->assertSame(1, $application->whatsappConnections()->count());
    }

    public function test_a_second_provider_connection_keeps_its_own_policy_and_does_not_become_fallback(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        config([
            'gateway.provider_endpoints.https_hosts' => array_values(array_unique([
                ...config('gateway.provider_endpoints.https_hosts', []),
                'secondary.waha.test',
            ])),
        ]);
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => 'fonnte-1']),
            'https://secondary.waha.test/*' => Http::response(['id' => 'waha-1'], 201),
        ]);

        $first = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['token' => 'fonnte-secret'],
            ],
        ])->assertCreated();

        $second = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'WAHA',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'waha',
                'configuration' => [
                    'base_url' => 'https://secondary.waha.test',
                    'session' => 'default',
                    'api_key' => 'waha-key',
                ],
            ],
        ])->assertCreated();

        $firstConnection = WhatsAppConnection::query()->where('uuid', $first->json('data.id'))->firstOrFail();
        $secondConnection = WhatsAppConnection::query()->where('uuid', $second->json('data.id'))->firstOrFail();

        $this->assertTrue((bool) $firstConnection->is_default);
        $this->assertFalse((bool) $secondConnection->is_default);
        $this->assertNotSame($firstConnection->routing_policy_id, $secondConnection->routing_policy_id);
        $this->assertSame(1, $firstConnection->routingPolicy->steps()->count());
        $this->assertSame(1, $secondConnection->routingPolicy->steps()->count());
        $this->assertSame('default', $firstConnection->routingPolicy->key);
        $this->assertNotSame('default', $secondConnection->routingPolicy->key);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$client['token'],
            'Idempotency-Key' => 'second-1',
        ])->postJson('/api/v1/messages', [
            'connection_id' => $secondConnection->uuid,
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'message' => ['type' => 'text', 'text' => 'Hanya WAHA'],
        ])->assertCreated()->assertJsonPath('data.provider', $secondConnection->providerAccount?->slug);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.fonnte.com'));
        $this->assertSame(1, $firstConnection->routingPolicy->fresh()->steps()->count());
    }

    public function test_unknown_connection_id_is_not_found(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$client['token'],
            'Idempotency-Key' => 'missing-1',
        ])->postJson('/api/v1/messages', [
            'connection_id' => '00000000-0000-4000-8000-000000000000',
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'message' => ['type' => 'text', 'text' => 'Halo'],
        ])
            ->assertNotFound()
            ->assertJsonPath('error.code', 'connection_not_found');
    }

    public function test_resume_rejects_a_type_change_for_the_same_name(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        Http::fake(['*' => Http::response(['ok' => true, 'status' => 'qr', 'qr' => 'data:image/png;base64,test'])]);

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => 'managed_number',
        ])->assertCreated();

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['token' => 'fonnte-secret'],
            ],
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'capability_not_supported');

        $this->assertSame(1, WhatsAppConnection::query()->count());
        $this->assertSame('managed_number', WhatsAppConnection::query()->sole()->type);
    }

    public function test_pairing_mode_requires_a_phone_number(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => 'managed_number',
            'mode' => 'pairing',
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed');

        $this->assertSame(0, WhatsAppConnection::query()->count());
    }

    public function test_listing_connections_does_not_write_status(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['token' => 'fonnte-secret'],
            ],
        ])->assertCreated();

        $connection = WhatsAppConnection::query()->sole();
        $updatedAt = (string) $connection->updated_at;
        $status = $connection->status;

        $this->withToken($client['token'])->getJson('/api/v1/connections')
            ->assertOk()
            ->assertJsonPath('data.0.status', ConnectionStatus::SetupRequired->value);

        $fresh = $connection->fresh();
        $this->assertSame($updatedAt, (string) $fresh->updated_at);
        $this->assertSame($status, $fresh->status);
    }
}
