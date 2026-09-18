<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\WhatsAppDevices;
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
            '*' => Http::response(['name' => 'default', 'status' => 'STOPPED'], 200),
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
            ->assertSee('Mesin engine')
            ->assertSee('Nomor dari CESA / Helpdesk / SAM')
            ->assertSee($host->name)
            ->assertSee($linked->name)
            ->assertSee('User CESA/Helpdesk/SAM menautkan nomor');

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
            ->assertSee('Satu tombol')
            ->assertSee('Hubungkan')
            ->assertDontSee('https://waha-engine.test');
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
