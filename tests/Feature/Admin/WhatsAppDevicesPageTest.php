<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\WhatsAppDevices;
use App\Filament\Resources\ProviderAccounts\Pages\CreateProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\EditProviderAccount;
use App\Models\ProviderAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class WhatsAppDevicesPageTest extends TestCase
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

        Http::fake([
            'waha-engine.test/*' => Http::response(['name' => 'default', 'status' => 'STOPPED'], 200),
        ]);
    }

    public function test_devices_page_groups_host_machines_apart_from_user_linked_numbers(): void
    {
        $host = $this->waha('waha-engine', [
            'base_url' => 'https://waha-engine.test',
            'session' => 'default',
            'api_key' => 'secret-key',
        ]);
        $linked = $this->waha('web-cesa-sess-rekrutmen-12', [
            'base_url' => 'https://waha-engine.test',
            'session' => 'rekrutmen-12',
            'api_key' => 'secret-key',
            'owned_by_application_id' => 1,
            'cesa_session_id' => 'rekrutmen-12',
        ]);

        $page = Livewire::test(WhatsAppDevices::class)
            ->assertSee('Nomor gateway')
            ->assertSee('Nomor aplikasi')
            ->assertSee($host->name)
            ->assertSee($linked->name)
            ->assertSee('perangkat WAHA');

        $this->assertSame([$host->id], $page->instance()->hostDevices()->pluck('id')->all());
        $this->assertSame([$linked->id], $page->instance()->linkedDevices()->pluck('id')->all());
    }

    public function test_linked_device_idle_copy_hides_engine_url(): void
    {
        $this->waha('web-helpdesk-sess-cs-1', [
            'base_url' => 'https://waha-engine.test',
            'session' => 'cs-1',
            'api_key' => 'secret-key',
            'owned_by_application_id' => 2,
            'cesa_session_id' => 'cs-1',
        ]);

        Livewire::test(WhatsAppDevices::class)
            ->assertSee('cs-1')
            ->assertSee('Hubungkan')
            ->assertDontSee('https://waha-engine.test');
    }

    public function test_admin_can_create_a_native_provider_without_external_credentials_and_connect_it(): void
    {
        config(['gateway.engine.baileys_url' => 'http://runner.test', 'gateway.engine.baileys_token' => 'secret']);
        Http::fake(['runner.test/*' => Http::response(['ok' => true, 'status' => 'qr', 'qr' => 'data:image/png;base64,qr'])]);
        Livewire::test(CreateProviderAccount::class)
            ->fillForm(['name' => 'WhatsApp Utama', 'driver' => 'wag_hub', 'is_active' => true, 'timeout_seconds' => 20])
            ->call('create')->assertHasNoFormErrors();
        $provider = ProviderAccount::query()->sole();
        $this->assertSame('wag_hub', $provider->driver);
        $page = Livewire::test(WhatsAppDevices::class)->call('selectDevice', $provider->id)->call('connect');
        $page->assertSet('qr', 'data:image/png;base64,qr');
        $this->assertSame('scan_qr', $provider->fresh()->session_status);
    }

    public function test_editing_an_application_native_provider_preserves_session_ownership(): void
    {
        $client = $this->createClientApplication();
        $configuration = ['owned_by_application_id' => $client['id'], 'client_session_id' => 'support-1'];
        $fixture = $this->createProviderAccount('wag_hub', 'app-native', $configuration);
        $provider = ProviderAccount::findOrFail($fixture['id']);
        Livewire::test(EditProviderAccount::class, ['record' => $provider->getRouteKey()])
            ->fillForm(['name' => 'Nomor Support'])->call('save')->assertHasNoFormErrors();
        $this->assertSame($configuration, $provider->fresh()->configuration);
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private function waha(string $slug, array $configuration): ProviderAccount
    {
        return ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', $slug, $configuration)['id'],
        );
    }
}
