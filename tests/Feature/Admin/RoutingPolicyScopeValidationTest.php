<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\RoutingPolicies\Pages\CreateRoutingPolicy;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class RoutingPolicyScopeValidationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_admin_cannot_create_a_duplicate_global_any_purpose_route(): void
    {
        $this->actingAs(User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]));
        RoutingPolicy::forceCreate([
            'name' => 'Existing global route',
            'key' => 'default',
            'purpose' => null,
            'is_default' => true,
            'is_active' => true,
        ]);
        $provider = ProviderAccount::forceCreate([
            'name' => 'Fonnte Primary',
            'slug' => 'fonnte-primary-scope-test',
            'driver' => 'fonnte',
            'configuration' => [
                'endpoint' => 'https://api.fonnte.com/send',
                'token' => 'provider-token',
            ],
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);

        Livewire::test(CreateRoutingPolicy::class)
            ->fillForm([
                'client_application_id' => null,
                'name' => 'Duplicate global route',
                'key' => 'default',
                'purpose' => null,
                'is_default' => true,
                'is_active' => true,
                'steps' => [
                    ['provider_account_id' => $provider->getKey(), 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors(['key']);

        $this->assertDatabaseCount('routing_policies', 1);
        $this->assertDatabaseCount('routing_steps', 0);
    }
}
