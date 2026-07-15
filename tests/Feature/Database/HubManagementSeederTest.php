<?php

namespace Tests\Feature\Database;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\User;
use Database\Seeders\GatewayHubManagementSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class HubManagementSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_an_administrator_client_applications_and_provider_routing(): void
    {
        config()->set('gateway.seed', [
            'administrator' => [
                'name' => 'Gateway Administrator',
                'email' => 'gateway.admin@example.test',
                'password' => 'Seeder-Administrator-123!',
            ],
            'providers' => [
                'waha' => [
                    'base_url' => 'https://waha.example.test',
                    'session' => 'gateway-main',
                    'api_key' => 'waha-seed-key',
                ],
                'fonnte' => [
                    'endpoint' => 'https://api.fonnte.com/send',
                    'token' => 'fonnte-seed-token',
                ],
            ],
            'credentials' => [
                'web-shelf' => 'wgh_shelf_seed_token',
            ],
        ]);

        $this->seed(GatewayHubManagementSeeder::class);

        $administrator = User::query()->where('email', 'gateway.admin@example.test')->sole();
        $this->assertTrue($administrator->is_admin);
        $this->assertTrue($administrator->is_active);
        $this->assertTrue(Hash::check('Seeder-Administrator-123!', $administrator->password));

        $this->assertSame(
            ['appscript-ft', 'web-helpdesk', 'web-sam', 'web-shelf'],
            ClientApplication::query()->orderBy('slug')->pluck('slug')->all(),
        );
        $this->assertSame(['fonnte-primary', 'waha-primary'], ProviderAccount::query()->orderBy('slug')->pluck('slug')->all());

        $shelf = ClientApplication::query()->where('slug', 'web-shelf')->sole();
        $policy = RoutingPolicy::query()
            ->where('client_application_id', $shelf->id)
            ->where('key', 'shelf-notifications')
            ->sole();

        $this->assertSame(['waha-primary', 'fonnte-primary'], $policy->steps->pluck('providerAccount.slug')->all());

        $credential = ApiCredential::query()->where('client_application_id', $shelf->id)->sole();
        $this->assertTrue(hash_equals(hash('sha256', 'wgh_shelf_seed_token'), $credential->getRawOriginal('token_hash')));
        $this->assertSame(['messages:send', 'messages:read'], $credential->abilities);
    }

    public function test_it_is_idempotent_and_does_not_create_provider_routes_without_provider_credentials(): void
    {
        config()->set('gateway.seed', [
            'administrator' => [
                'name' => 'Gateway Administrator',
                'email' => 'gateway.admin@example.test',
                'password' => 'Seeder-Administrator-123!',
            ],
            'providers' => ['waha' => [], 'fonnte' => []],
            'credentials' => [],
        ]);

        $this->seed(GatewayHubManagementSeeder::class);
        $this->seed(GatewayHubManagementSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('client_applications', 4);
        $this->assertDatabaseCount('provider_accounts', 0);
        $this->assertDatabaseCount('routing_policies', 0);
        $this->assertDatabaseCount('api_credentials', 0);
    }
}
