<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\NumberCheckRequests\Pages\ViewNumberCheckRequest;
use App\Models\NumberCheckRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

final class NumberCheckAuditResourceTest extends TestCase
{
    use BuildsGatewayFixtures;
    use DatabaseMigrations;

    public function test_admin_can_trace_the_requester_and_attempt_without_exposing_the_full_number(): void
    {
        $administrator = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);
        $client = $this->createClientApplication(['numbers:check']);
        $provider = $this->createProviderAccount('waha', 'admin-audit-waha', [
            'base_url' => 'https://admin-audit.test',
            'session' => 'default',
            'api_key' => 'secret',
        ]);
        $this->createRoutingPolicy($client['id'], [$provider['id']], operation: 'number_check');
        Http::fake([
            'admin-audit.test/*' => Http::response(['numberExists' => true]),
        ]);

        $this->postJson('/api/v1/number-checks', [
            'recipient' => ['type' => 'phone', 'value' => '081234567890'],
        ], [
            'Authorization' => "Bearer {$client['token']}",
            'X-Correlation-ID' => 'admin-audit-check',
        ])->assertOk();

        $audit = NumberCheckRequest::query()
            ->with(['clientApplication', 'apiCredential'])
            ->sole();
        $this->actingAs($administrator);

        Livewire::test(ViewNumberCheckRequest::class, ['record' => $audit->getKey()])
            ->assertSee('Detail pengecekan nomor')
            ->assertSee($audit->clientApplication->name)
            ->assertSee($audit->apiCredential->name)
            ->assertSee('••••••••7890')
            ->assertSee('Admin Audit Waha')
            ->assertSee('admin-audit-check')
            ->assertDontSee('6281234567890');
    }
}
