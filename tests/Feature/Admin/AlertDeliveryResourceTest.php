<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\AlertDeliveries\AlertDeliveryResource;
use App\Models\AlertDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class AlertDeliveryResourceTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_admin_can_list_and_view_alert_deliveries(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $provider = $this->createProviderAccount('waha', 'waha-alert-delivery', [
            'base_url' => 'https://waha-alert-delivery.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);

        $delivery = AlertDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'provider_account_id' => $provider['id'],
            'user_id' => $admin->id,
            'channel' => 'telegram',
            'from_status' => 'healthy',
            'to_status' => 'unavailable',
            'consecutive_failures' => 3,
            'circuit_open_until' => now()->addMinutes(5),
            'error_summary' => 'down',
            'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->get('/panel/alert-deliveries')
            ->assertOk();

        $this->actingAs($admin)
            ->get('/panel/alert-deliveries/'.$delivery->uuid)
            ->assertOk()
            ->assertSee('telegram');
    }

    public function test_alert_deliveries_resource_is_read_only(): void
    {
        $this->assertFalse(AlertDeliveryResource::canCreate());
    }
}
