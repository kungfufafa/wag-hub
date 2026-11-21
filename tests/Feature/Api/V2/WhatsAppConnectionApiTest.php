<?php

namespace Tests\Feature\Api\V2;

use App\Domain\Connections\ConnectionStatus;
use App\Jobs\EngineDispatchMessage;
use App\Jobs\ProcessEngineOperation;
use App\Models\ClientApplication;
use App\Models\EngineIntegration;
use App\Models\EngineMessage;
use App\Models\EngineOperation;
use App\Models\EngineSession;
use App\Models\GatewayMessage;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\WhatsAppConnection;
use App\Services\Connections\ConnectionHealthProjector;
use App\Services\Connections\ConnectionStatusProjector;
use App\Services\Engine\SessionService;
use App\Services\GatewayMessageDispatcher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class WhatsAppConnectionApiTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config([
            'gateway.engine.baileys_url' => 'http://runner.test',
            'gateway.engine.baileys_token' => 'private-test-token',
        ]);
        Http::preventStrayRequests();
    }

    public function test_managed_number_create_is_a_v2_session_and_replays_by_idempotency_key(): void
    {
        $client = $this->client();

        $created = $this->createManaged($client, 'connect-1', ['name' => 'Support'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Support')
            ->assertJsonPath('data.type', 'managed_number')
            ->assertJsonPath('data.status', ConnectionStatus::Connecting->value)
            ->assertJsonPath('data.recommended_action', 'Scan QR')
            ->assertJsonPath('data.session.desired_state', 'RUNNING')
            ->assertJsonPath('data.operation.action', 'start')
            ->assertJsonPath('data.operation.status', 'pending')
            ->assertJsonPath('data.operation.retryable', true)
            ->assertJsonMissingPath('data.token');

        $session = EngineSession::query()->sole();
        $this->assertSame($session->uuid, $created->json('data.session.id'));
        $this->assertSame('connect-1', $session->creation_key);
        $this->assertSame('Support', $session->name);
        $this->assertSame($session->id, WhatsAppConnection::query()->sole()->engine_session_id);
        // No legacy native session, provider account or route is created for a managed number.
        $this->assertSame(0, ProviderAccount::query()->count());
        $this->assertSame(0, RoutingPolicy::query()->count());
        Queue::assertPushed(ProcessEngineOperation::class, 1);

        $this->createManaged($client, 'connect-1', ['name' => 'Support'])
            ->assertCreated()
            ->assertJsonPath('data.id', $created->json('data.id'));
        $this->createManaged($client, 'connect-1', ['name' => 'Different'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict')
            ->assertJsonPath('error.retryable', false);

        $this->assertSame(1, WhatsAppConnection::query()->count());
        $this->assertSame(1, EngineSession::query()->count());
        $this->assertSame(1, EngineOperation::query()->count());

        // The managed number is an ordinary v2 session.
        $this->withToken($client['token'])->getJson('/api/v2/sessions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $session->uuid);
    }

    public function test_create_requires_an_idempotency_key_and_a_pairing_phone(): void
    {
        $client = $this->client();

        $this->withToken($client['token'])->postJson('/api/v2/connections', ['type' => 'managed_number'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('idempotency_key');
        $this->createManaged($client, 'pair-1', ['auth_method' => 'pairing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
        $this->assertSame(0, WhatsAppConnection::query()->count());

        $this->createManaged($client, 'pair-2', ['auth_method' => 'pairing', 'phone' => '6281234567890'])
            ->assertCreated()
            ->assertJsonPath('data.recommended_action', 'Enter pairing code');

        $operation = EngineOperation::query()->sole();
        $this->assertSame(['mode' => 'pairing', 'phone' => '6281234567890'], $operation->payload);
    }

    public function test_capacity_exceeded_creates_no_connection(): void
    {
        $client = $this->client();
        app(SessionService::class)->integration(ClientApplication::query()->findOrFail($client['id']));
        EngineIntegration::query()->where('client_application_id', $client['id'])->update(['configured_limit' => 1]);

        $this->createManaged($client, 'one')->assertCreated();
        $this->createManaged($client, 'two', ['name' => 'Second'])
            ->assertConflict()
            ->assertJsonPath('message', 'The account limit has been reached.')
            ->assertJsonPath('error.code', 'capacity_exceeded')
            ->assertJsonPath('error.retryable', false);

        $this->assertSame(1, WhatsAppConnection::query()->count());
        $this->assertSame(1, EngineSession::query()->count());
    }

    public function test_status_qr_and_readiness_come_from_the_engine_session(): void
    {
        $client = $this->client();
        $id = $this->createManaged($client, 'status-1')->assertCreated()->json('data.id');
        $session = EngineSession::query()->sole();
        $this->settle($session, 'SCAN_QR_CODE', ['auth_method' => 'qr']);
        Http::fake([
            'runner.test/sessions/*/auth/qr' => Http::response(['qr' => 'data:image/png;base64,abc', 'generation' => 1, 'expires_at' => now()->addMinute()->toISOString()]),
            'runner.test/sessions/*' => Http::response(['canonical_status' => 'SCAN_QR_CODE', 'auth_method' => 'qr', 'generation' => 1, 'revision' => 1]),
        ]);

        $this->withToken($client['token'])->getJson('/api/v2/connections/'.$id)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', ConnectionStatus::Connecting->value)
            ->assertJsonPath('data.recommended_action', 'Scan QR')
            ->assertJsonPath('data.qr', 'data:image/png;base64,abc');
        // Listing never fetches or returns authentication secrets.
        $this->withToken($client['token'])->getJson('/api/v2/connections')
            ->assertOk()
            ->assertJsonMissingPath('data.0.qr');

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['runner.test/sessions/*' => Http::response(['canonical_status' => 'WORKING', 'phone_number' => '6281111111111', 'generation' => 1, 'revision' => 2])]);

        $this->withToken($client['token'])->getJson('/api/v2/connections/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', ConnectionStatus::Ready->value)
            ->assertJsonPath('data.recommended_action', null)
            ->assertJsonPath('data.sender.phone', '6281111111111')
            ->assertJsonPath('data.session.status', 'WORKING')
            ->assertJsonMissingPath('data.qr');
        $this->assertTrue($this->hydrated()->canAttemptSend());

        // A WORKING session that is no longer observed is not ready to send.
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $session->refresh()->update(['observed_at' => now()->subMinutes(5)]);

        $this->withToken($client['token'])->getJson('/api/v2/connections/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', ConnectionStatus::Degraded->value)
            ->assertJsonPath('data.session.stale', true);
        $this->assertFalse($this->hydrated()->canAttemptSend());
    }

    public function test_runner_outage_is_a_retryable_pending_operation(): void
    {
        $client = $this->client();
        $id = $this->createManaged($client, 'outage-1')->assertCreated()->json('data.id');
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        app(SessionService::class)->execute(EngineOperation::query()->sole());

        $this->withToken($client['token'])->getJson('/api/v2/connections/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', ConnectionStatus::SetupRequired->value)
            ->assertJsonPath('data.recommended_action', 'Start WAG Hub runner')
            ->assertJsonPath('data.session.last_error', 'engine_unreachable')
            ->assertJsonPath('data.operation.status', 'pending')
            ->assertJsonPath('data.operation.retryable', true);
    }

    public function test_connect_and_retry_issue_session_start_commands(): void
    {
        $client = $this->client();
        $id = $this->createManaged($client, 'cmd-1')->assertCreated()->json('data.id');
        $session = EngineSession::query()->sole();
        $this->settle($session, 'STOPPED', ['desired_state' => 'STOPPED']);

        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$id.'/connect')
            ->assertAccepted()
            ->assertJsonPath('data.session.desired_state', 'RUNNING')
            ->assertJsonPath('data.operation.action', 'start')
            ->assertJsonPath('data.operation.status', 'pending');
        // Retrying while the same start is pending reuses it.
        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$id.'/retry')->assertAccepted();
        $this->assertSame(2, EngineOperation::query()->count());

        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$id.'/connect', ['auth_method' => 'pairing'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$id.'/connect', ['auth_method' => 'pairing', 'phone' => '6281234567890'])
            ->assertAccepted()
            ->assertJsonPath('data.recommended_action', 'Enter pairing code');
        $this->assertSame(['mode' => 'pairing', 'phone' => '6281234567890'], EngineOperation::query()->latest('id')->first()->payload);

        // A removed session is replaced by a new v2 session on reconnect.
        $session->refresh()->delete();
        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$id.'/retry')->assertAccepted();
        $replacement = EngineSession::query()->sole();
        $this->assertNotSame($session->id, $replacement->id);
        $this->assertSame($replacement->id, WhatsAppConnection::query()->sole()->engine_session_id);
    }

    public function test_managed_send_uses_the_v2_transport_with_replay_before_readiness(): void
    {
        $client = $this->client();
        $id = $this->createManaged($client, 'send-1')->assertCreated()->json('data.id');
        $session = EngineSession::query()->sole();
        $this->settle($session, 'WORKING');
        $payload = ['to' => '6281234567890', 'text' => 'Halo'];

        $first = $this->send($client, $id, 'msg-1', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.connection_id', $id)
            ->assertJsonPath('data.session_id', $session->uuid)
            ->assertJsonPath('data.transport_status', 'queued');
        $this->send($client, $id, 'msg-1', $payload)->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
        $this->send($client, $id, 'msg-1', [...$payload, 'text' => 'Lain'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->send($client, $id, '', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_idempotency_key');
        $this->assertSame(1, EngineMessage::query()->count());
        Queue::assertPushed(EngineDispatchMessage::class, 1);

        $session->refresh()->update(['observed_at' => now()->subMinutes(5)]);

        $this->send($client, $id, 'msg-2', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'connection_not_ready')
            ->assertJsonPath('error.internal_code', 'session_not_working')
            ->assertJsonPath('error.retryable', true);
        $this->send($client, $id, 'msg-1', $payload)->assertAccepted()->assertJsonPath('data.id', $first->json('data.id'));
    }

    public function test_provider_route_create_and_send_use_the_routed_gateway_pipeline(): void
    {
        $client = $this->client();
        Http::fake(['https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => 'fonnte-1'])]);

        $created = $this->createProvider($client, 'route-1', 'Fonnte', 'fonnte', ['token' => 'fonnte-secret'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'provider_route')
            ->assertJsonPath('data.status', ConnectionStatus::SetupRequired->value)
            ->assertJsonPath('data.recommended_action', 'Send a test message')
            ->assertJsonPath('data.sender.driver', 'fonnte')
            ->assertJsonMissingPath('data.session');
        $this->assertStringNotContainsString('fonnte-secret', (string) json_encode($created->json()));
        $this->createProvider($client, 'route-1', 'Fonnte', 'fonnte', ['token' => 'fonnte-secret'])
            ->assertCreated()
            ->assertJsonPath('data.id', $created->json('data.id'));
        $this->assertSame(1, ProviderAccount::query()->count());
        $this->assertSame(0, EngineSession::query()->count());
        $policy = RoutingPolicy::query()->where('operation', 'message')->sole();
        $this->assertSame('default', $policy->key);

        $id = $created->json('data.id');
        $payload = ['to' => '6281234567890', 'text' => 'Halo'];
        $first = $this->send($client, $id, 'route-msg-1', $payload)
            ->assertCreated()
            ->assertJsonPath('data.connection_id', $id)
            ->assertJsonPath('data.transport_status', 'accepted');

        $message = DB::table('gateway_messages')->sole();
        $connection = WhatsAppConnection::query()->sole();
        $this->assertSame($first->json('data.id'), $message->uuid);
        $this->assertSame($connection->id, $message->whatsapp_connection_id);
        $this->assertSame($connection->routing_policy_id, $message->routing_policy_id);

        // Replay is answered from the ledger before readiness checks.
        ProviderAccount::query()->whereKey($connection->provider_account_id)->update(['is_active' => false]);
        $this->send($client, $id, 'route-msg-2', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'connection_not_ready')
            ->assertJsonPath('error.retryable', true);
        $this->send($client, $id, 'route-msg-1', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->send($client, $id, 'route-msg-1', [...$payload, 'text' => 'Lain'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(1, DB::table('gateway_messages')->count());
    }

    public function test_provider_route_rejects_media_and_the_builtin_driver(): void
    {
        $client = $this->client();
        $id = $this->createProvider($client, 'route-1', 'Fonnte', 'fonnte', ['token' => 'fonnte-secret'])->assertCreated()->json('data.id');

        $this->send($client, $id, 'image-1', ['to' => '6281234567890', 'type' => 'image', 'media_id' => '00000000-0000-4000-8000-000000000000'])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'capability_not_supported');
        $this->createProvider($client, 'native-1', 'Native', 'wag_hub', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('provider.driver');
    }

    public function test_routed_connection_may_use_configured_fallback(): void
    {
        $client = $this->client();
        Http::fake([
            'https://primary.waha.test/*' => Http::response(['error' => 'rejected'], 400),
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => 'fonnte-1']),
        ]);
        $id = $this->createProvider($client, 'primary-1', 'Primary', 'waha', [
            'base_url' => 'https://primary.waha.test', 'session' => 'default', 'api_key' => 'waha-key',
        ])->assertCreated()->json('data.id');
        $connection = WhatsAppConnection::query()->sole();
        $fallback = $this->createProviderAccount('fonnte', 'fonnte-secondary', [
            'endpoint' => 'https://api.fonnte.com/send', 'token' => 'fonnte-token',
        ]);
        DB::table('routing_steps')->insert([
            'routing_policy_id' => $connection->routing_policy_id, 'provider_account_id' => $fallback['id'],
            'position' => 2, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->send($client, $id, 'fallback-1', ['to' => '6281234567890', 'text' => 'Fallback ok'])->assertCreated();

        $this->assertSame(2, DB::table('message_attempts')->count());
        $this->assertSame($fallback['id'], DB::table('gateway_messages')->value('accepted_provider_account_id'));
    }

    public function test_a_second_provider_connection_keeps_its_own_policy(): void
    {
        $client = $this->client();
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => 'fonnte-1']),
            'https://secondary.waha.test/*' => Http::response(['id' => 'waha-1'], 201),
        ]);
        $first = $this->createProvider($client, 'first', 'Fonnte', 'fonnte', ['token' => 'fonnte-secret'])->assertCreated();
        $second = $this->createProvider($client, 'second', 'WAHA', 'waha', [
            'base_url' => 'https://secondary.waha.test', 'session' => 'default', 'api_key' => 'waha-key',
        ])->assertCreated();

        $firstConnection = WhatsAppConnection::query()->where('uuid', $first->json('data.id'))->firstOrFail();
        $secondConnection = WhatsAppConnection::query()->where('uuid', $second->json('data.id'))->firstOrFail();
        $this->assertTrue((bool) $firstConnection->is_default);
        $this->assertFalse((bool) $secondConnection->is_default);
        $this->assertNotSame($firstConnection->routing_policy_id, $secondConnection->routing_policy_id);
        $this->assertNotSame('default', $secondConnection->routingPolicy->key);

        $this->send($client, $secondConnection->uuid, 'second-1', ['to' => '6281234567890', 'text' => 'Hanya WAHA'])->assertCreated();

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.fonnte.com'));
        $this->assertSame(1, $firstConnection->routingPolicy->fresh()->steps()->count());
    }

    public function test_retry_recovers_a_provider_connection_whose_first_validation_failed(): void
    {
        $client = $this->client();

        $this->createProvider($client, 'fix-1', 'Fonnte', 'fonnte', ['token' => ''])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Provider credentials are incomplete: token is required.')
            ->assertJsonPath('error.code', 'connection_not_ready');
        $connection = WhatsAppConnection::query()->sole();
        $this->assertNull($connection->provider_account_id);

        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$connection->uuid.'/retry')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'connection_not_ready');
        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$connection->uuid.'/retry', [
            'provider' => ['configuration' => ['token' => 'fixed-token']],
        ])->assertOk()->assertJsonPath('data.sender.driver', 'fonnte');

        $this->assertSame('fixed-token', $connection->fresh()->providerAccount->configuration['token']);
    }

    public function test_another_tenants_connection_is_not_found(): void
    {
        $owner = $this->client();
        $other = $this->client();
        $id = $this->createManaged($owner, 'mine')->assertCreated()->json('data.id');
        $this->settle(EngineSession::query()->sole(), 'WORKING');

        $this->withToken($other['token'])->getJson('/api/v2/connections/'.$id)
            ->assertNotFound()
            ->assertJsonPath('error.code', 'connection_not_found');
        $this->withToken($other['token'])->postJson('/api/v2/connections/'.$id.'/connect')->assertNotFound();
        $this->withToken($other['token'])->postJson('/api/v2/connections/'.$id.'/retry')->assertNotFound();
        $this->send($other, $id, 'steal-1', ['to' => '6281234567890', 'text' => 'Halo'])->assertNotFound();
        $this->withToken($other['token'])->getJson('/api/v2/connections')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, EngineMessage::query()->count());
    }

    public function test_abilities_are_enforced(): void
    {
        $owner = $this->client();
        $id = $this->createManaged($owner, 'abilities')->assertCreated()->json('data.id');
        $reader = $this->createClientApplication(['sessions:read']);
        $sender = $this->createClientApplication(['messages:send']);

        $this->withToken($reader['token'])->getJson('/api/v2/connections')->assertOk();
        $this->withToken($reader['token'])->postJson('/api/v2/connections', ['type' => 'managed_number'], ['Idempotency-Key' => 'r-1'])->assertForbidden();
        $this->withToken($reader['token'])->postJson('/api/v2/connections/'.$id.'/connect')->assertForbidden();
        $this->withToken($reader['token'])->postJson('/api/v2/connections/'.$id.'/retry')->assertForbidden();
        $this->send($reader, $id, 'r-2', ['to' => '6281234567890', 'text' => 'Halo'])->assertForbidden();
        $this->withToken($sender['token'])->getJson('/api/v2/connections')->assertForbidden();
        $this->withToken($sender['token'])->getJson('/api/v2/connections/'.$id)->assertForbidden();
        $this->withToken('wgh_invalid')->getJson('/api/v2/connections')->assertUnauthorized();
    }

    public function test_v1_connection_routes_are_gone_and_v1_messages_keep_their_contract(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read', ...config('engine_v2.scopes')]);

        $this->withToken($client['token'])->getJson('/api/v1/connections')->assertNotFound();
        $this->withToken($client['token'])->postJson('/api/v1/connections', ['type' => 'managed_number'])->assertNotFound();
        $this->withToken($client['token'])->postJson('/api/v1/connections/00000000-0000-4000-8000-000000000000/messages')->assertNotFound();

        // Without the branch's connection defaults, v1 still requires purpose and mode.
        $this->withHeaders(['Authorization' => 'Bearer '.$client['token'], 'Idempotency-Key' => 'v1-1'])
            ->postJson('/api/v1/messages', [
                'recipient' => ['type' => 'phone', 'value' => '081234567890'],
                'message' => ['type' => 'text', 'text' => 'Halo'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrors(['purpose', 'mode']);
        $this->postMessage($client['token'], 'v1-2', $this->messagePayload('sync'))
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'route_unavailable')
            ->assertJsonMissingPath('error.action')
            ->assertJsonMissingPath('data.connection_id');
    }

    public function test_engine_sessions_are_projected_as_managed_connections_idempotently(): void
    {
        $client = $this->client();
        $session = $this->withToken($client['token'])->postJson('/api/v2/sessions', ['name' => 'HR'], ['Idempotency-Key' => 'direct-1'])
            ->assertCreated()->json('data.session.id');

        $this->withToken($client['token'])->getJson('/api/v2/connections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'managed_number')
            ->assertJsonPath('data.0.name', 'HR')
            ->assertJsonPath('data.0.session.id', $session)
            ->assertJsonPath('data.0.is_default', true);
        $this->withToken($client['token'])->getJson('/api/v2/connections')->assertOk()->assertJsonCount(1, 'data');
        $this->artisan('gateway:sync-connections')->assertSuccessful();

        $this->assertSame(1, WhatsAppConnection::query()->count());
        $this->assertSame(1, ClientApplication::query()->findOrFail($client['id'])->whatsappConnections()->count());
    }

    public function test_sessions_mapped_by_legacy_migration_link_their_existing_connection(): void
    {
        $client = $this->client();
        $native = $this->createProviderAccount('wag_hub', 'mapped-native', [
            'owned_by_application_id' => $client['id'], 'engine_session_id' => 'legacy-ref',
        ]);
        // A connection projected before v2 points at the legacy native account.
        $legacy = WhatsAppConnection::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'client_application_id' => $client['id'],
            'name' => 'Legacy', 'type' => 'managed_number', 'status' => 'setup_required',
            'is_default' => true, 'provider_account_id' => $native['id'],
        ]);

        $this->artisan('engine:migrate-legacy')->assertSuccessful();
        $this->artisan('gateway:sync-connections')->assertSuccessful();
        $this->artisan('gateway:sync-connections')->assertSuccessful();

        $mapped = EngineSession::query()->where('external_reference', 'legacy-ref')->sole();
        $this->assertSame(1, WhatsAppConnection::query()->count());
        $this->assertSame($mapped->id, $legacy->fresh()->engine_session_id);
        $this->withToken($client['token'])->getJson('/api/v2/connections')
            ->assertOk()
            ->assertJsonPath('data.0.id', $legacy->uuid)
            ->assertJsonPath('data.0.session.id', $mapped->uuid);
    }

    public function test_read_only_credentials_never_receive_the_qr_or_pairing_code(): void
    {
        $client = $this->client();
        $id = $this->createManaged($client, 'secret-1')->assertCreated()->json('data.id');
        $session = EngineSession::query()->sole();
        $this->settle($session, 'SCAN_QR_CODE', ['auth_method' => 'qr']);
        Http::fake([
            'runner.test/sessions/*/auth/qr' => Http::response(['qr' => 'data:image/png;base64,abc', 'generation' => 1, 'expires_at' => now()->addMinute()->toISOString()]),
            'runner.test/sessions/*' => Http::response(['canonical_status' => 'SCAN_QR_CODE', 'auth_method' => 'qr', 'generation' => 1, 'revision' => 1]),
        ]);
        // A second credential of the same application that may only read sessions.
        $reader = $this->createApiCredential($client['id'], ['sessions:read']);

        $this->withToken($reader['token'])->getJson('/api/v2/sessions/'.$session->uuid.'/auth/qr')->assertForbidden();
        $this->withToken($reader['token'])->getJson('/api/v2/connections/'.$id)
            ->assertOk()
            ->assertJsonPath('data.status', ConnectionStatus::Connecting->value)
            ->assertJsonPath('data.recommended_action', 'Scan QR')
            ->assertJsonMissingPath('data.qr')
            ->assertJsonMissingPath('data.pairing_code')
            ->assertJsonMissingPath('data.challenge_expires_at');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/auth/qr'));

        $this->withToken($client['token'])->getJson('/api/v2/connections/'.$id)
            ->assertOk()
            ->assertJsonPath('data.qr', 'data:image/png;base64,abc');
    }

    public function test_a_routed_send_never_falls_back_to_another_connections_provider(): void
    {
        $client = $this->client();
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => 'fonnte-1']),
            'https://secondary.waha.test/*' => Http::response(['id' => 'waha-1'], 201),
        ]);
        // Connection A holds the application's default policy; B has its own dedicated policy.
        $this->createProvider($client, 'a', 'Fonnte', 'fonnte', ['token' => 'fonnte-secret'])->assertCreated();
        $b = $this->createProvider($client, 'b', 'WAHA', 'waha', [
            'base_url' => 'https://secondary.waha.test', 'session' => 'default', 'api_key' => 'waha-key',
        ])->assertCreated()->json('data.id');
        $connection = WhatsAppConnection::query()->where('uuid', $b)->firstOrFail();
        $this->send($client, $b, 'b-1', ['to' => '6281234567890', 'text' => 'Halo'])->assertCreated();
        ProviderAccount::query()->whereKey($connection->provider_account_id)->update(['health_status' => 'healthy']);
        RoutingPolicy::query()->whereKey($connection->routing_policy_id)->update(['is_active' => false]);

        $this->send($client, $b, 'b-2', ['to' => '6281234567890', 'text' => 'Halo'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'connection_not_ready')
            ->assertJsonPath('error.retryable', true);
        $this->withToken($client['token'])->getJson('/api/v2/connections/'.$b)
            ->assertOk()
            ->assertJsonPath('data.status', ConnectionStatus::Disconnected->value);

        // A message already pinned to B (e.g. queued before the policy was disabled) fails
        // instead of being routed through the application's default policy.
        $original = GatewayMessage::query()->where('idempotency_key', 'b-1')->sole();
        $pinned = $original->replicate();
        $pinned->forceFill([
            'uuid' => (string) Str::uuid(), 'idempotency_key' => 'b-3', 'status' => 'queued',
            'accepted_provider_account_id' => null, 'provider_message_id' => null, 'provider_accepted_at' => null,
        ])->save();

        $result = app(GatewayMessageDispatcher::class)->dispatch($pinned);

        $this->assertSame('failed', $result->fresh()->status);
        $this->assertSame('route_unavailable', $result->fresh()->last_error_code);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'api.fonnte.com'));
    }

    public function test_managed_send_replays_after_the_session_is_replaced(): void
    {
        $client = $this->client();
        $id = $this->createManaged($client, 'replace-1')->assertCreated()->json('data.id');
        $session = EngineSession::query()->sole();
        $this->settle($session, 'WORKING');
        $payload = ['to' => '6281234567890', 'text' => 'Halo'];
        $first = $this->send($client, $id, 'm1', $payload)->assertAccepted();

        $session->refresh()->delete();
        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$id.'/retry')->assertAccepted();
        $replacement = WhatsAppConnection::query()->sole()->engine_session_id;
        $this->assertNotSame($session->id, $replacement);

        $this->send($client, $id, 'm1', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.session_id', $session->uuid);
        $this->send($client, $id, 'm1', [...$payload, 'text' => 'Lain'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->send($client, $id, 'm2', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'connection_not_ready');

        // A key used on a session that never belonged to this connection is still a conflict.
        $other = $this->withToken($client['token'])->postJson('/api/v2/sessions', ['name' => 'Other'], ['Idempotency-Key' => 'other-session'])
            ->assertCreated()->json('data.session.id');
        $this->settle(EngineSession::query()->where('uuid', $other)->sole(), 'WORKING');
        $this->withToken($client['token'])->postJson('/api/v2/sessions/'.$other.'/messages', $payload, ['Idempotency-Key' => 'foreign-1'])
            ->assertAccepted();
        $this->settle(EngineSession::query()->findOrFail($replacement), 'WORKING');
        $this->send($client, $id, 'foreign-1', $payload)
            ->assertConflict()
            ->assertJsonPath('error.code', 'idempotency_conflict');
        $this->assertSame(2, EngineMessage::query()->count());
    }

    public function test_a_pending_teardown_is_reported_as_disconnected(): void
    {
        $projector = app(ConnectionStatusProjector::class);

        foreach (['stop', 'logout', 'delete'] as $action) {
            $snapshot = [
                'status' => 'WORKING', 'desired_state' => 'STOPPED', 'stale' => false, 'deleted' => false,
                'auth_method' => 'qr', 'last_error' => null, 'pending_operation' => ['action' => $action, 'status' => 'pending'],
            ];
            $status = $projector->projectEngineSession($snapshot);

            $this->assertSame(ConnectionStatus::Disconnected, $status, $action);
            $this->assertSame('Reconnect', $projector->recommendedEngineAction($status, $snapshot), $action);
        }

        $starting = ['status' => 'STOPPED', 'desired_state' => 'RUNNING', 'stale' => false, 'deleted' => false,
            'auth_method' => 'qr', 'last_error' => null, 'pending_operation' => ['action' => 'start', 'status' => 'pending']];
        $this->assertSame(ConnectionStatus::Connecting, $projector->projectEngineSession($starting));

        $client = $this->client();
        $id = $this->createManaged($client, 'teardown-1')->assertCreated()->json('data.id');
        $session = EngineSession::query()->sole();
        $this->settle($session, 'WORKING');
        $this->withToken($client['token'])->postJson('/api/v2/sessions/'.$session->uuid.'/stop')->assertAccepted();

        $this->withToken($client['token'])->getJson('/api/v2/connections')
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.status', ConnectionStatus::Disconnected->value)
            ->assertJsonPath('data.0.recommended_action', 'Reconnect')
            ->assertJsonPath('data.0.operation.action', 'stop');
    }

    public function test_create_with_a_key_already_used_for_a_session_does_not_depend_on_a_prior_read(): void
    {
        foreach ([false, true] as $readFirst) {
            $client = $this->client();
            $session = $this->withToken($client['token'])->postJson('/api/v2/sessions', ['name' => 'WhatsApp', 'start' => true], ['Idempotency-Key' => 'shared-key'])
                ->assertCreated()->json('data.session.id');

            if ($readFirst) {
                // Projects the session as a managed connection.
                $this->withToken($client['token'])->getJson('/api/v2/connections')->assertOk()->assertJsonCount(1, 'data');
            }

            $created = $this->createManaged($client, 'shared-key')
                ->assertCreated()
                ->assertJsonPath('data.session.id', $session);
            $this->createManaged($client, 'shared-key')
                ->assertCreated()
                ->assertJsonPath('data.id', $created->json('data.id'));
            $this->createManaged($client, 'shared-key', ['name' => 'Different'])
                ->assertConflict()
                ->assertJsonPath('error.code', 'idempotency_conflict');

            $this->assertSame(1, EngineSession::query()->where('client_application_id', $client['id'])->count(), 'read first: '.var_export($readFirst, true));
            $this->assertSame(1, WhatsAppConnection::query()->where('client_application_id', $client['id'])->count(), 'read first: '.var_export($readFirst, true));
        }
    }

    private function hydrated(): WhatsAppConnection
    {
        return app(ConnectionHealthProjector::class)->hydrate(WhatsAppConnection::query()->sole());
    }

    /**
     * @return array<string, mixed>
     */
    private function client(): array
    {
        return $this->createClientApplication(config('engine_v2.scopes'));
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>  $input
     */
    private function createManaged(array $client, string $key, array $input = [])
    {
        return $this->withToken($client['token'])->postJson('/api/v2/connections', [
            'type' => 'managed_number', ...$input,
        ], ['Idempotency-Key' => $key]);
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>  $configuration
     */
    private function createProvider(array $client, string $key, string $name, string $driver, array $configuration)
    {
        return $this->withToken($client['token'])->postJson('/api/v2/connections', [
            'name' => $name,
            'type' => 'provider_route',
            'provider' => ['driver' => $driver, 'configuration' => $configuration],
        ], ['Idempotency-Key' => $key]);
    }

    /**
     * @param  array<string, mixed>  $client
     * @param  array<string, mixed>  $payload
     */
    private function send(array $client, string $id, string $key, array $payload)
    {
        return $this->withToken($client['token'])->postJson('/api/v2/connections/'.$id.'/messages', $payload, ['Idempotency-Key' => $key]);
    }

    /**
     * Mark the pending start as carried out and the session as freshly observed in $status.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function settle(EngineSession $session, string $status, array $overrides = []): void
    {
        EngineOperation::query()->where('engine_session_id', $session->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $session->refresh()->update([
            'status' => $status, 'desired_state' => 'RUNNING', 'pending_operation_id' => null,
            'observed_at' => now(), 'engine_available' => true, 'last_error' => null,
            'authenticated_at' => $status === 'WORKING' ? now() : null, ...$overrides,
        ]);
    }
}
