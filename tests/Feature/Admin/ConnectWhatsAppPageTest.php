<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ConnectWhatsApp;
use App\Filament\Resources\WhatsAppConnections\Pages\ViewWhatsAppConnection;
use App\Jobs\ProcessEngineOperation;
use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\EngineIntegration;
use App\Models\EngineSession;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Engine\SessionService;
use App\Services\IntegrationPack;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ConnectWhatsAppPageTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]));

        config(['gateway.dispatch' => 'sync']);
        Http::preventStrayRequests();
    }

    public function test_the_application_query_parameter_resolves_strictly_by_uuid_or_numeric_id(): void
    {
        $first = ClientApplication::query()->create([
            'name' => 'Alpha',
            'slug' => 'alpha-web',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
        $second = ClientApplication::query()->create([
            'name' => 'Beta',
            'slug' => 'beta-web',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);

        Livewire::withQueryParams(['application' => $second->uuid])
            ->test(ConnectWhatsApp::class)
            ->assertSet('applicationId', $second->getKey());

        Livewire::withQueryParams(['application' => (string) $second->getKey()])
            ->test(ConnectWhatsApp::class)
            ->assertSet('applicationId', $second->getKey());

        Livewire::withQueryParams(['application' => $first->getKey().'.0'])
            ->test(ConnectWhatsApp::class)
            ->assertSet('applicationId', null);

        Livewire::withQueryParams(['application' => $first->getKey().'abc'])
            ->test(ConnectWhatsApp::class)
            ->assertSet('applicationId', null);
    }

    public function test_a_second_wizard_connection_does_not_steal_the_default(): void
    {
        $application = ClientApplication::query()->create([
            'name' => 'HR',
            'slug' => 'hr-web',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);

        Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('name', 'Fonnte')
            ->set('type', 'provider_route')
            ->set('driver', 'fonnte')
            ->set('token', 'fonnte-one')
            ->call('createConnection')
            ->assertSet('step', 3);

        Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('name', 'Cadangan')
            ->set('type', 'provider_route')
            ->set('driver', 'fonnte')
            ->set('token', 'fonnte-two')
            ->call('createConnection')
            ->assertSet('step', 3);

        $first = WhatsAppConnection::query()->where('name', 'Fonnte')->sole();
        $second = WhatsAppConnection::query()->where('name', 'Cadangan')->sole();

        $this->assertTrue((bool) $first->is_default);
        $this->assertFalse((bool) $second->is_default);
        $this->assertNotSame($first->routing_policy_id, $second->routing_policy_id);
        $this->assertSame(1, $first->routingPolicy->steps()->count());
        $this->assertSame(1, $second->routingPolicy->steps()->count());
    }

    public function test_wizard_test_send_uses_the_connection_send_path_and_copy_config_does_not_mint_again(): void
    {
        $application = ClientApplication::query()->create([
            'name' => 'HR',
            'slug' => 'hr-web',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
        ApiCredential::issue($application, 'Existing', app(IntegrationPack::class)->abilities());
        Http::fake([
            'https://api.fonnte.com/*' => Http::response(['status' => true, 'id' => 'fonnte-test']),
        ]);

        $page = Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('name', 'Fonnte')
            ->set('type', 'provider_route')
            ->set('driver', 'fonnte')
            ->set('token', 'fonnte-secret')
            ->call('createConnection')
            ->assertSet('step', 3)
            ->set('step', 4)
            ->set('testRecipient', '081234567890')
            ->set('testText', 'Tes koneksi WAG Hub')
            ->call('sendTest')
            ->assertSet('step', 5);

        $connection = WhatsAppConnection::query()->sole();
        $message = DB::table('gateway_messages')->sole();
        $this->assertSame($connection->id, $message->whatsapp_connection_id);
        $this->assertSame($connection->routing_policy_id, $message->routing_policy_id);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'api.fonnte.com'));

        $page->call('issueIntegration');
        $this->assertSame(1, $application->apiCredentials()->count());
        $this->assertStringContainsString('WAG_URL=', (string) $page->get('issuedEnv'));
        $this->assertStringContainsString('…', (string) $page->get('issuedEnv'));
    }

    public function test_wizard_blocks_test_send_while_a_managed_number_is_still_connecting(): void
    {
        Queue::fake();
        config([
            'gateway.engine.baileys_url' => 'http://runner.test',
            'gateway.engine.baileys_token' => 'private-runner-token',
        ]);
        $application = $this->application();

        $page = Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('name', 'Support')
            ->set('type', 'managed_number')
            ->set('mode', 'qr')
            ->call('createConnection')
            ->assertSet('step', 3);

        // The managed number is a v2 engine session; no legacy native account or route is created.
        $connection = WhatsAppConnection::query()->sole();
        $session = EngineSession::query()->sole();
        $this->assertSame($session->id, $connection->engine_session_id);
        $this->assertSame('RUNNING', $session->desired_state);
        $this->assertSame(0, ProviderAccount::query()->count());
        Queue::assertPushed(ProcessEngineOperation::class, 1);

        // A double click replays the same attempt instead of creating another session.
        $page->call('createConnection')->assertSet('connectionUuid', $connection->uuid);
        $this->assertSame(1, EngineSession::query()->count());

        $this->assertFalse($page->instance()->canSendTest());
        $page->set('testRecipient', '081234567890')->call('sendTest');
        $this->assertSame(0, DB::table('engine_messages')->count());
        $this->assertSame(0, DB::table('gateway_messages')->count());
    }

    public function test_wizard_shows_the_qr_from_the_engine_challenge(): void
    {
        Queue::fake();
        config([
            'gateway.engine.baileys_url' => 'http://runner.test',
            'gateway.engine.baileys_token' => 'private-runner-token',
        ]);
        $application = $this->application();
        $page = Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('type', 'managed_number')
            ->call('createConnection');
        $session = EngineSession::query()->sole();
        $session->pendingOperation()->update(['status' => 'completed']);
        $session->update(['status' => 'SCAN_QR_CODE', 'pending_operation_id' => null, 'observed_at' => now(), 'engine_available' => true]);
        Http::fake(['runner.test/sessions/*/auth/qr' => Http::response(['qr' => 'data:image/png;base64,challenge', 'generation' => $session->generation])]);

        $this->assertSame('data:image/png;base64,challenge', $page->instance()->presentation()['qr']);
        $page->call('$refresh')->assertSee('data:image/png;base64,challenge', false);
    }

    public function test_capacity_errors_are_shown_as_notifications(): void
    {
        Queue::fake();
        $application = $this->application();
        app(SessionService::class)->integration($application);
        EngineIntegration::query()->where('client_application_id', $application->getKey())->update(['configured_limit' => 0]);

        Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('step', 2)
            ->set('type', 'managed_number')
            ->call('createConnection')
            ->assertNotified('Kuota nomor WhatsApp aplikasi ini sudah penuh')
            ->assertSet('step', 2);

        $this->assertSame(0, WhatsAppConnection::query()->count());
        $this->assertSame(0, EngineSession::query()->count());
    }

    public function test_admin_reconnect_reports_capacity_when_the_session_was_removed(): void
    {
        Queue::fake();
        $application = $this->application();
        Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('type', 'managed_number')
            ->call('createConnection');
        EngineSession::query()->sole()->delete();
        EngineIntegration::query()->where('client_application_id', $application->getKey())->update(['configured_limit' => 0]);

        Livewire::test(ViewWhatsAppConnection::class, ['record' => WhatsAppConnection::query()->sole()->getRouteKey()])
            ->assertActionVisible('reconnect')
            ->callAction('reconnect')
            ->assertNotified('Kuota nomor WhatsApp aplikasi ini sudah penuh');

        EngineIntegration::query()->where('client_application_id', $application->getKey())->update(['configured_limit' => 1]);

        Livewire::test(ViewWhatsAppConnection::class, ['record' => WhatsAppConnection::query()->sole()->getRouteKey()])
            ->callAction('reconnect')
            ->assertNotified('Koneksi diperbarui');
        $this->assertSame(EngineSession::query()->sole()->id, WhatsAppConnection::query()->sole()->engine_session_id);
    }

    public function test_connection_view_lists_each_capability_once(): void
    {
        Queue::fake();
        $application = $this->application();
        Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('type', 'managed_number')
            ->call('createConnection');
        $connection = WhatsAppConnection::query()->sole();
        $capabilities = implode(', ', $connection->capabilities);
        $this->assertNotSame('', $capabilities);

        $html = Livewire::test(ViewWhatsAppConnection::class, ['record' => $connection->getRouteKey()])->html();

        $this->assertSame(1, substr_count($html, e($capabilities)));
    }

    private function application(): ClientApplication
    {
        return ClientApplication::query()->create([
            'name' => 'HR',
            'slug' => 'hr-web',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
    }
}
