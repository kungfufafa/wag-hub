<?php

namespace Tests\Feature\Api\V2;

use App\Filament\Resources\WhatsAppConnections\Pages\ViewWhatsAppConnection;
use App\Infrastructure\WhatsApp\HostResolver;
use App\Models\ClientApplication;
use App\Models\EngineSession;
use App\Models\ProviderAccount;
use App\Models\RoutingStep;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Engine\SessionService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ConnectionTenantIsolationTest extends TestCase
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

    public function test_tenant_cannot_overwrite_a_shared_admin_provider_account(): void
    {
        [$client, $shared, $connection] = $this->syncedSharedRoute();
        $before = $connection->fresh()->only(['status', 'last_error_code', 'last_error_message', 'setup_state']);

        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$connection->uuid.'/retry', [
            'provider' => ['configuration' => ['endpoint' => 'https://attacker.example/send', 'token' => 'x']],
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'capability_not_supported');

        $account = ProviderAccount::query()->findOrFail($shared['id']);
        $this->assertSame('fonnte', $account->driver);
        $this->assertSame(['endpoint' => 'https://api.fonnte.com/send', 'token' => 'ADMIN-SECRET'], $account->configuration);
        // The refused request leaves no error on the shared connection.
        $this->assertSame($before, $connection->fresh()->only(array_keys($before)));
    }

    public function test_a_connection_named_like_a_shared_route_gets_its_own_account(): void
    {
        [$client, $shared, $connection] = $this->syncedSharedRoute();

        $this->withToken($client['token'])->postJson('/api/v2/connections', [
            'name' => $connection->name,
            'type' => 'provider_route',
            'provider' => ['driver' => 'fonnte', 'configuration' => ['token' => 'tenant-token']],
        ], ['Idempotency-Key' => 'same-name'])->assertCreated();

        $this->assertSame(['endpoint' => 'https://api.fonnte.com/send', 'token' => 'ADMIN-SECRET'], ProviderAccount::query()->findOrFail($shared['id'])->configuration);
        $this->assertSame(2, ProviderAccount::query()->count());
        $this->assertSame($shared['id'], $connection->fresh()->provider_account_id);
    }

    public function test_tenant_never_receives_the_qr_of_a_shared_admin_session(): void
    {
        $client = $this->client();
        $shared = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha.example.com', 'api_key' => 'ADMIN-KEY', 'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$shared['id']]);
        $this->withToken($client['token'])->getJson('/api/v2/connections')->assertOk();
        $connection = WhatsAppConnection::query()->sole();
        $this->assertSame($shared['id'], $connection->provider_account_id);

        ProviderAccount::query()->whereKey($shared['id'])->update(['session_status' => 'scan_qr']);
        Http::fake(['*' => Http::response(['mimetype' => 'image/png', 'data' => base64_encode('qr')])]);

        $this->withToken($client['token'])->getJson('/api/v2/connections/'.$connection->uuid)
            ->assertOk()
            ->assertJsonMissingPath('data.qr')
            ->assertJsonMissingPath('data.pairing_code');
        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$connection->uuid.'/connect')
            ->assertOk()
            ->assertJsonMissingPath('data.qr')
            ->assertJsonMissingPath('data.pairing_code');

        Http::assertNothingSent();
    }

    public function test_a_managed_connection_never_exposes_or_commands_another_tenants_session(): void
    {
        $owner = $this->client();
        $intruder = $this->client();
        $foreign = app(SessionService::class)->create(ClientApplication::query()->findOrFail($owner['id']), 'owner-session', ['name' => 'Owner']);
        $foreign->update(['status' => 'WORKING', 'desired_state' => 'RUNNING', 'generation' => 1, 'observed_at' => now(), 'engine_available' => true]);
        // A corrupt row points the intruder's connection at the owner's session.
        $connection = WhatsAppConnection::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'client_application_id' => $intruder['id'],
            'name' => 'Forged', 'type' => 'managed_number', 'status' => 'ready', 'is_default' => true,
            'engine_session_id' => $foreign->id,
        ]);

        $this->withToken($intruder['token'])->getJson('/api/v2/connections/'.$connection->uuid)
            ->assertOk()
            ->assertJsonPath('data.session', null)
            ->assertJsonPath('data.status', 'setup_required')
            ->assertJsonPath('data.sender', null);
        $this->withToken($intruder['token'])->postJson('/api/v2/connections/'.$connection->uuid.'/messages', [
            'to' => '6281234567890', 'text' => 'Halo',
        ], ['Idempotency-Key' => 'forged-1'])
            ->assertConflict()
            ->assertJsonPath('error.code', 'connection_not_ready');

        $this->assertSame(0, DB::table('engine_messages')->count());
        $this->assertSame(1, $foreign->fresh()->generation);
        Http::assertNothingSent();
    }

    public function test_admin_reconnect_is_hidden_for_a_shared_admin_session(): void
    {
        $client = $this->client();
        $shared = $this->createProviderAccount('waha', 'waha-primary', [
            'base_url' => 'https://waha.example.com', 'api_key' => 'ADMIN-KEY', 'session' => 'default',
        ]);
        $this->createRoutingPolicy($client['id'], [$shared['id']]);
        $this->withToken($client['token'])->getJson('/api/v2/connections')->assertOk();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => true]));

        Livewire::test(ViewWhatsAppConnection::class, ['record' => WhatsAppConnection::query()->sole()->getRouteKey()])
            ->assertActionHidden('reconnect');
    }

    public function test_retry_does_not_reenable_a_disabled_shared_account_or_step(): void
    {
        [$client, $shared, $connection] = $this->syncedSharedRoute();
        ProviderAccount::query()->whereKey($shared['id'])->update(['is_active' => false]);
        RoutingStep::query()->where('provider_account_id', $shared['id'])->update(['is_active' => false]);

        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$connection->uuid.'/retry')->assertOk();

        $account = ProviderAccount::query()->findOrFail($shared['id']);
        $this->assertFalse($account->is_active);
        $this->assertArrayNotHasKey('whatsapp_connection_id', $account->configuration);
        $this->assertFalse((bool) RoutingStep::query()->where('provider_account_id', $shared['id'])->sole()->is_active);
        $this->assertSame(1, DB::table('routing_policies')->count());
    }

    public function test_retry_does_not_reenable_a_disabled_tenant_account_or_step(): void
    {
        $client = $this->client();
        $this->withToken($client['token'])->postJson('/api/v2/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => ['driver' => 'fonnte', 'configuration' => ['token' => 'fonnte-secret']],
        ], ['Idempotency-Key' => 'tenant-1'])->assertCreated();

        $connection = WhatsAppConnection::query()->sole();
        ProviderAccount::query()->whereKey($connection->provider_account_id)->update(['is_active' => false]);
        RoutingStep::query()->where('provider_account_id', $connection->provider_account_id)->update(['is_active' => false]);

        $this->withToken($client['token'])->postJson('/api/v2/connections/'.$connection->uuid.'/retry')->assertOk();

        $this->assertFalse(ProviderAccount::query()->findOrFail($connection->provider_account_id)->is_active);
        $this->assertSame(0, RoutingStep::query()
            ->where('provider_account_id', $connection->provider_account_id)
            ->where('is_active', true)
            ->count());
    }

    #[DataProvider('internalEndpoints')]
    public function test_tenant_endpoints_pointing_at_internal_hosts_are_rejected(string $endpoint): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return $host === 'internal.example.com' ? ['10.1.2.3'] : ['93.184.216.34'];
            }
        });
        $client = $this->client();

        $this->withToken($client['token'])->postJson('/api/v2/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['token' => 'fonnte-secret', 'endpoint' => $endpoint],
            ],
        ], ['Idempotency-Key' => 'ssrf-1'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Provider endpoint is not allowed.');

        $this->assertSame(0, ProviderAccount::query()->count());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function internalEndpoints(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/send'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'private' => ['http://10.0.0.5/send'];
        yield 'decimal loopback' => ['http://2130706433/send'];
        yield 'hostname resolving to private' => ['https://internal.example.com/send'];
    }

    public function test_tenant_endpoints_are_checked_again_when_the_request_is_sent(): void
    {
        $client = $this->client();
        Http::fake(['*' => Http::response(['status' => true, 'id' => 'fonnte-1'])]);

        $id = $this->withToken($client['token'])->postJson('/api/v2/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['token' => 'fonnte-secret', 'endpoint' => 'https://rebind.example.com/send'],
            ],
        ], ['Idempotency-Key' => 'rebind-connection'])->assertCreated()->json('data.id');

        // DNS now answers with an internal address (rebinding after validation).
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['169.254.169.254'];
            }
        });

        $response = $this->withToken($client['token'])->postJson('/api/v2/connections/'.$id.'/messages', [
            'to' => '6281234567890', 'text' => 'Hello',
        ], ['Idempotency-Key' => 'rebind-1']);

        $this->assertGreaterThanOrEqual(400, $response->status());
        Http::assertNothingSent();
        $this->assertSame('provider_endpoint_not_allowed', DB::table('message_attempts')->value('error_code'));
    }

    /**
     * @return array<string, mixed>
     */
    private function client(): array
    {
        return $this->createClientApplication(config('engine_v2.scopes'));
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{id: int, slug: string}, 2: WhatsAppConnection}
     */
    private function syncedSharedRoute(): array
    {
        $client = $this->client();
        $shared = $this->createProviderAccount('fonnte', 'shared-fonnte', [
            'endpoint' => 'https://api.fonnte.com/send',
            'token' => 'ADMIN-SECRET',
        ]);
        $this->createRoutingPolicy($client['id'], [$shared['id']]);

        $this->withToken($client['token'])->getJson('/api/v2/connections')->assertOk();

        $connection = WhatsAppConnection::query()->sole();
        $this->assertSame($shared['id'], $connection->provider_account_id);
        $this->assertNull(EngineSession::query()->first());

        return [$client, $shared, $connection];
    }
}
