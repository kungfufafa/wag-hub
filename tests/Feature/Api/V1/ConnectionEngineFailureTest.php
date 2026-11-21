<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Connections\ConnectionStatus;
use App\Filament\Resources\WhatsAppConnections\Pages\ViewWhatsAppConnection;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ConnectionEngineFailureTest extends TestCase
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

    public function test_idempotent_replay_returns_the_accepted_message_after_the_connection_degrades(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        Http::fake(['https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => 'fonnte-1'])]);

        $created = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => ['driver' => 'fonnte', 'configuration' => ['token' => 'fonnte-secret']],
        ])->assertCreated();
        $payload = [
            'connection_id' => $created->json('data.id'),
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
            'message' => ['type' => 'text', 'text' => 'Halo'],
        ];
        $headers = ['Authorization' => 'Bearer '.$client['token'], 'Idempotency-Key' => 'replay-1'];

        $first = $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)->assertCreated();

        $connection = WhatsAppConnection::query()->sole();
        $connection->forceFill(['status' => ConnectionStatus::Disconnected->value])->save();
        ProviderAccount::query()->whereKey($connection->provider_account_id)->update(['is_active' => false]);

        $this->withHeaders([...$headers, 'Idempotency-Key' => 'fresh-1'])
            ->postJson('/api/v1/messages', $payload)
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'connection_not_ready');

        $this->withHeaders($headers)->postJson('/api/v1/messages', $payload)
            ->assertSuccessful()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.status', 'provider_accepted');

        $this->withHeaders($headers)
            ->postJson('/api/v1/messages', [...$payload, 'message' => ['type' => 'text', 'text' => 'Lain']])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'idempotency_conflict');
    }

    public function test_unreachable_runner_is_a_retryable_not_ready_connection(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => 'managed_number',
        ])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'connection_not_ready')
            ->assertJsonPath('error.retryable', true);

        $connection = WhatsAppConnection::query()->sole();
        $this->assertSame(ConnectionStatus::SetupRequired->value, $connection->status);
        $this->assertSame('Start WAG Hub runner', $connection->recommended_action);
        $this->assertSame('connection_not_ready', $connection->last_error_code);
    }

    public function test_connect_and_admin_reconnect_report_an_unreachable_runner(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);
        Http::fake(['*' => Http::response(['ok' => true, 'status' => 'qr', 'qr' => 'data:image/png;base64,test'])]);

        $created = $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Support',
            'type' => 'managed_number',
        ])->assertCreated();

        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->withToken($client['token'])->postJson('/api/v1/connections/'.$created->json('data.id').'/connect')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'connection_not_ready')
            ->assertJsonPath('error.internal_code', 'connection_not_ready')
            ->assertJsonPath('error.retryable', true);

        $this->actingAs(User::factory()->create(['is_admin' => true, 'is_active' => true]));

        Livewire::test(ViewWhatsAppConnection::class, ['record' => WhatsAppConnection::query()->sole()->getRouteKey()])
            ->callAction('reconnect')
            ->assertNotified('Gagal menghubungkan ulang');
    }

    public function test_pending_engine_operation_on_connect_is_a_retryable_json_error(): void
    {
        Queue::fake();
        $client = $this->createClientApplication(['messages:send', 'messages:read', 'engine:use']);
        $this->createProviderAccount('wag_hub', 'mapped-native', [
            'owned_by_application_id' => $client['id'], 'engine_session_id' => 'legacy-ref',
        ]);
        $this->artisan('engine:migrate-legacy')->assertSuccessful();

        $id = $this->withToken($client['token'])->getJson('/api/v1/connections')->assertOk()->json('data.0.id');
        $this->assertIsString($id);
        Http::fake(fn () => Http::response(['error' => 'offline'], 503));

        $this->withToken($client['token'])->postJson('/api/v1/connections/'.$id.'/connect')
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'connection_not_ready')
            ->assertJsonPath('error.internal_code', 'operation_pending')
            ->assertJsonPath('error.retryable', true);
    }

    public function test_retry_recovers_a_provider_connection_whose_first_validation_failed(): void
    {
        $client = $this->createClientApplication(['messages:send', 'messages:read']);

        $this->withToken($client['token'])->postJson('/api/v1/connections', [
            'name' => 'Fonnte',
            'type' => 'provider_route',
            'provider' => ['driver' => 'fonnte', 'configuration' => ['token' => '']],
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Provider credentials are incomplete: token is required.');

        $connection = WhatsAppConnection::query()->sole();
        $this->assertNull($connection->provider_account_id);

        $this->withToken($client['token'])->postJson('/api/v1/connections/'.$connection->uuid.'/retry')
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'connection_not_ready')
            ->assertJsonPath('message', 'Provider credentials are incomplete: token is required.');

        $this->withToken($client['token'])->postJson('/api/v1/connections/'.$connection->uuid.'/retry', [
            'provider' => ['configuration' => ['token' => 'fixed-token']],
        ])
            ->assertOk()
            ->assertJsonPath('data.sender.driver', 'fonnte');

        $account = $connection->fresh()->providerAccount;
        $this->assertNotNull($account);
        $this->assertSame('fonnte', $account->driver);
        $this->assertSame('fixed-token', $account->configuration['token']);
    }
}
