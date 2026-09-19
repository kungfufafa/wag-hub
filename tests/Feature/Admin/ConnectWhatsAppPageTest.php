<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ConnectWhatsApp;
use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function test_wizard_test_send_uses_the_messages_api_and_copy_config_does_not_mint_again(): void
    {
        $application = ClientApplication::query()->create([
            'name' => 'HR',
            'slug' => 'hr-web',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
        ApiCredential::issue($application, 'Existing', ['messages:send', 'messages:read']);
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
        config([
            'gateway.engine.driver' => 'wag_hub',
            'gateway.engine.baileys_url' => 'http://runner.test:3318',
            'gateway.engine.baileys_token' => 'private-runner-token',
        ]);
        Http::fake([
            '*/sessions' => Http::response(['ok' => true, 'status' => 'qr', 'qr' => 'data:image/png;base64,test']),
            '*/sessions/*' => Http::response(['ok' => true, 'status' => 'qr', 'qr' => 'data:image/png;base64,test']),
        ]);

        $application = ClientApplication::query()->create([
            'name' => 'HR',
            'slug' => 'hr-web',
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);

        $page = Livewire::test(ConnectWhatsApp::class)
            ->set('applicationId', $application->getKey())
            ->set('name', 'Support')
            ->set('type', 'managed_number')
            ->set('mode', 'qr')
            ->call('createConnection')
            ->assertSet('step', 3);

        $this->assertFalse($page->instance()->canSendTest());

        $page->set('testRecipient', '081234567890')->call('sendTest');
        $this->assertSame(0, DB::table('gateway_messages')->count());
    }
}
