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

    public function test_it_seeds_the_default_administrator_when_no_seed_credentials_are_configured(): void
    {
        config()->set('gateway.seed.providers', ['waha' => [], 'fonnte' => [], 'gowa' => [], 'waba' => []]);
        config()->set('gateway.seed.credentials', []);

        $this->seed(GatewayHubManagementSeeder::class);

        $administrator = User::query()->where('email', 'admin@gateway.local')->sole();

        $this->assertTrue($administrator->is_admin);
        $this->assertTrue(Hash::check('admin12345', $administrator->password));
    }

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
                'gowa' => [
                    'base_url' => 'https://gowa.example.test',
                    'username' => 'gateway',
                    'password' => 'gowa-seed-password',
                    'device_id' => 'device-main',
                ],
                'waba' => [
                    'base_url' => 'https://graph.facebook.com',
                    'api_version' => 'v25.0',
                    'phone_number_id' => '123456789012345',
                    'access_token' => 'waba-seed-token',
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
        $this->assertSame(
            ['fonnte-primary', 'gowa-primary', 'waba-primary', 'waha-primary'],
            ProviderAccount::query()->orderBy('slug')->pluck('slug')->all(),
        );

        $shelf = ClientApplication::query()->where('slug', 'web-shelf')->sole();
        $policy = RoutingPolicy::query()
            ->where('client_application_id', $shelf->id)
            ->where('operation', 'message')
            ->where('key', 'shelf-notifications')
            ->sole();

        $this->assertSame(
            ['waha-primary', 'fonnte-primary', 'gowa-primary', 'waba-primary'],
            $policy->steps->pluck('providerAccount.slug')->all(),
        );
        $numberCheckPolicy = RoutingPolicy::query()
            ->where('client_application_id', $shelf->id)
            ->where('operation', 'number_check')
            ->where('key', 'default')
            ->sole();
        $this->assertSame(
            ['waha-primary', 'fonnte-primary', 'gowa-primary'],
            $numberCheckPolicy->steps->pluck('providerAccount.slug')->all(),
        );

        $credential = ApiCredential::query()->where('client_application_id', $shelf->id)->sole();
        $this->assertTrue(hash_equals(hash('sha256', 'wgh_shelf_seed_token'), $credential->getRawOriginal('token_hash')));
        $this->assertSame(['messages:send', 'messages:read'], $credential->abilities);
    }

    public function test_it_is_idempotent_and_seeds_disabled_provider_accounts_and_routes_without_provider_credentials(): void
    {
        config()->set('gateway.seed', [
            'administrator' => [
                'name' => 'Gateway Administrator',
                'email' => 'gateway.admin@example.test',
                'password' => 'Seeder-Administrator-123!',
            ],
            'providers' => ['waha' => [], 'fonnte' => [], 'gowa' => [], 'waba' => []],
            'credentials' => [],
        ]);

        $this->seed(GatewayHubManagementSeeder::class);
        $this->seed(GatewayHubManagementSeeder::class);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('client_applications', 4);
        $this->assertDatabaseCount('provider_accounts', 4);
        $this->assertDatabaseCount('routing_policies', 8);
        $this->assertDatabaseCount('api_credentials', 0);
        $this->assertFalse(ProviderAccount::query()->where('slug', 'waha-primary')->sole()->is_active);
        $this->assertFalse(ProviderAccount::query()->where('slug', 'fonnte-primary')->sole()->is_active);
        $this->assertFalse(ProviderAccount::query()->where('slug', 'gowa-primary')->sole()->is_active);
        $this->assertFalse(ProviderAccount::query()->where('slug', 'waba-primary')->sole()->is_active);
    }
}
