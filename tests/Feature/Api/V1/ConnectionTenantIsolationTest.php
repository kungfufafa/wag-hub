<?php

namespace Tests\Feature\Api\V1;

use App\Infrastructure\WhatsApp\HostResolver;
use App\Models\ProviderAccount;
use App\Models\RoutingStep;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

        config(['gateway.dispatch' => 'sync']);
        Http::preventStrayRequests();
    }

    public function test_tenant_cannot_overwrite_a_shared_admin_provider_account(): void
    {
        [$client, $shared, $connection] = $this->syncedSharedRoute();

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => $connection->name,
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['endpoint' => 'https://attacker.example/send'],
            ],
        ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'capability_not_supported');

        $account = ProviderAccount::query()->findOrFail($shared['id']);
        $this->assertSame('fonnte', $account->driver);
        $this->assertSame([
            'endpoint' => 'https://api.fonnte.com/send',
            'token' => 'ADMIN-SECRET',
        ], $account->configuration);
        $this->assertSame(1, ProviderAccount::query()->count());
    }

    public function test_retry_does_not_reenable_a_disabled_shared_account_or_step(): void
    {
        [$client, $shared, $connection] = $this->syncedSharedRoute();
        ProviderAccount::query()->whereKey($shared['id'])->update(['is_active' => false]);
        RoutingStep::query()->where('provider_account_id', $shared['id'])->update(['is_active' => false]);

        $this->withToken($client['token'])->postJson('/api/v1/connections/'.$connection->uuid.'/retry')
            ->assertOk();

        $account = ProviderAccount::query()->findOrFail($shared['id']);
        $this->assertFalse($account->is_active);
        $this->assertArrayNotHasKey('whatsapp_connection_id', $account->configuration);
        $this->assertFalse((bool) RoutingStep::query()->where('provider_account_id', $shared['id'])->sole()->is_active);
        $this->assertSame(1, DB::table('routing_policies')->count());
    }

    public function test_retry_does_not_reenable_a_disabled_tenant_account_or_step(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => ['driver' => 'fonnte', 'configuration' => ['token' => 'fonnte-secret']],
        ])->assertCreated();

        $connection = WhatsAppConnection::query()->sole();
        ProviderAccount::query()->whereKey($connection->provider_account_id)->update(['is_active' => false]);
        RoutingStep::query()->where('provider_account_id', $connection->provider_account_id)->update(['is_active' => false]);

        $this->withToken($client['token'])->postJson('/api/v1/connections/'.$connection->uuid.'/retry')
            ->assertOk();

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
        $client = $this->createClientApplication(['messages:send', 'messages:read']);

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['token' => 'fonnte-secret', 'endpoint' => $endpoint],
            ],
        ])->assertStatus(422);

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
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        Http::fake(['*' => Http::response(['status' => true, 'id' => 'fonnte-1'])]);

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => [
                'driver' => 'fonnte',
                'configuration' => ['token' => 'fonnte-secret', 'endpoint' => 'https://rebind.example.com/send'],
            ],
        ])->assertCreated();

        // DNS now answers with an internal address (rebinding after validation).
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['169.254.169.254'];
            }
        });

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$client['token'],
            'Idempotency-Key' => 'rebind-1',
        ])->postJson('/api/v1/messages', [
            'connection_id' => WhatsAppConnection::query()->sole()->uuid,
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'message' => ['type' => 'text', 'text' => 'Hello'],
        ]);

        $this->assertGreaterThanOrEqual(400, $response->status());
        Http::assertNothingSent();
        $this->assertSame(
            'provider_endpoint_not_allowed',
            DB::table('message_attempts')->value('error_code'),
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: array{id: int, slug: string}, 2: WhatsAppConnection}
     */
    private function syncedSharedRoute(): array
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        $shared = $this->createProviderAccount('fonnte', 'shared-fonnte', [
            'endpoint' => 'https://api.fonnte.com/send',
            'token' => 'ADMIN-SECRET',
        ]);
        $this->createRoutingPolicy($client['id'], [$shared['id']]);

        $this->withToken($client['token'])->getJson('/api/v1/connections')->assertOk();

        $connection = WhatsAppConnection::query()->sole();
        $this->assertSame($shared['id'], $connection->provider_account_id);

        return [$client, $shared, $connection];
    }
}
