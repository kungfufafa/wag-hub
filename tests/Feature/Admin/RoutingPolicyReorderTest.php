<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\RoutingPolicies\Pages\EditRoutingPolicy;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\RoutingStep;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Livewire\Livewire;
use Tests\TestCase;

class RoutingPolicyReorderTest extends TestCase
{
    use DatabaseMigrations;

    public function test_admin_can_reorder_routing_steps_without_unique_constraint_errors(): void
    {
        $this->actingAs(User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]));

        $primary = ProviderAccount::forceCreate([
            'name' => 'Primary Provider',
            'slug' => 'primary-provider-reorder',
            'driver' => 'fonnte',
            'configuration' => [
                'endpoint' => 'https://api.fonnte.com/send',
                'token' => 'primary-token',
            ],
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);
        $secondary = ProviderAccount::forceCreate([
            'name' => 'Secondary Provider',
            'slug' => 'secondary-provider-reorder',
            'driver' => 'waha',
            'configuration' => [
                'base_url' => 'https://waha.example.test',
                'session' => 'default',
                'api_key' => 'secondary-key',
            ],
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);

        $policy = RoutingPolicy::forceCreate([
            'name' => 'Reorderable route',
            'operation' => 'message',
            'key' => 'default',
            'purpose' => 'notification',
            'is_default' => true,
            'is_active' => true,
        ]);

        $firstStep = RoutingStep::query()->create([
            'routing_policy_id' => $policy->getKey(),
            'provider_account_id' => $primary->getKey(),
            'position' => 1,
            'is_active' => true,
        ]);
        $secondStep = RoutingStep::query()->create([
            'routing_policy_id' => $policy->getKey(),
            'provider_account_id' => $secondary->getKey(),
            'position' => 2,
            'is_active' => true,
        ]);

        $component = Livewire::test(EditRoutingPolicy::class, ['record' => $policy->getKey()]);

        $stepsState = $component->get('data.steps');
        $this->assertSame(
            ['record-'.$firstStep->getKey(), 'record-'.$secondStep->getKey()],
            array_keys($stepsState),
        );

        $component
            ->set('data.steps', array_reverse($stepsState, preserve_keys: true))
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('routing_steps', [
            'id' => $secondStep->getKey(),
            'position' => 1,
        ]);
        $this->assertDatabaseHas('routing_steps', [
            'id' => $firstStep->getKey(),
            'position' => 2,
        ]);
    }
}
