<?php

namespace Tests\Feature\Database;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\User;
use App\Models\WhatsAppConnection;
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
                'web-cesa' => 'wgh_cesa_seed_token',
            ],
            'engine_credentials' => [
                'web-cesa' => 'wgh_cesa_engine_token',
            ],
        ]);

        $this->seed(GatewayHubManagementSeeder::class);

        $administrator = User::query()->where('email', 'gateway.admin@example.test')->sole();
        $this->assertTrue($administrator->is_admin);
        $this->assertTrue($administrator->is_active);
        $this->assertTrue(Hash::check('Seeder-Administrator-123!', $administrator->password));

        $this->assertSame(
            ['appscript-ft', 'web-cesa', 'web-helpdesk', 'web-sam', 'web-shelf'],
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
        $this->assertFalse(
            RoutingPolicy::query()
                ->where('client_application_id', $shelf->id)
                ->where('operation', 'number_check')
                ->exists(),
        );

        $cesa = ClientApplication::query()->where('slug', 'web-cesa')->sole();
        $messagePolicy = RoutingPolicy::query()
            ->where('client_application_id', $cesa->id)
            ->where('operation', 'message')
            ->where('key', 'web-cesa-messages')
            ->sole();
        $this->assertSame(
            ['waha-primary', 'fonnte-primary', 'gowa-primary', 'waba-primary'],
            $messagePolicy->steps->pluck('providerAccount.slug')->all(),
        );

        $numberCheckPolicy = RoutingPolicy::query()
            ->where('client_application_id', $cesa->id)
            ->where('operation', 'number_check')
            ->where('key', 'lead-number-check')
            ->sole();
        $this->assertSame(
            ['waha-primary', 'fonnte-primary', 'gowa-primary'],
            $numberCheckPolicy->steps->pluck('providerAccount.slug')->all(),
        );

        $this->assertSame(5, WhatsAppConnection::query()->count());
        $this->assertTrue(
            WhatsAppConnection::query()
                ->where('client_application_id', $shelf->id)
                ->where('is_default', true)
                ->where('type', 'provider_route')
                ->exists(),
        );

        $shelfCredential = ApiCredential::query()->where('client_application_id', $shelf->id)->sole();
        $this->assertTrue(hash_equals(hash('sha256', 'wgh_shelf_seed_token'), $shelfCredential->getRawOriginal('token_hash')));
        $this->assertSame(['messages:send', 'messages:read', 'engine:use'], $shelfCredential->abilities);

        $cesaHub = ApiCredential::query()
            ->where('client_application_id', $cesa->id)
            ->where('name', 'Seeded application token')
            ->sole();
        $this->assertTrue(hash_equals(hash('sha256', 'wgh_cesa_seed_token'), $cesaHub->getRawOriginal('token_hash')));
        $this->assertSame(['messages:send', 'messages:read', 'numbers:check', 'engine:use'], $cesaHub->abilities);

        $cesaEngine = ApiCredential::query()
            ->where('client_application_id', $cesa->id)
            ->where('name', 'Seeded engine token')
            ->sole();
        $this->assertTrue(hash_equals(hash('sha256', 'wgh_cesa_engine_token'), $cesaEngine->getRawOriginal('token_hash')));
        $this->assertSame(['engine:use'], $cesaEngine->abilities);
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
        $this->assertDatabaseCount('client_applications', 5);
        $this->assertDatabaseCount('provider_accounts', 4);
        $this->assertDatabaseCount('routing_policies', 6);
        $this->assertDatabaseCount('whatsapp_connections', 5);
        $this->assertDatabaseCount('api_credentials', 0);
        $this->assertFalse(ProviderAccount::query()->where('slug', 'waha-primary')->sole()->is_active);
        $this->assertFalse(ProviderAccount::query()->where('slug', 'fonnte-primary')->sole()->is_active);
        $this->assertFalse(ProviderAccount::query()->where('slug', 'gowa-primary')->sole()->is_active);
        $this->assertFalse(ProviderAccount::query()->where('slug', 'waba-primary')->sole()->is_active);
    }

    public function test_rerunning_the_seeder_retires_legacy_number_check_routes_outside_web_cesa(): void
    {
        config()->set('gateway.seed.providers', ['waha' => [], 'fonnte' => [], 'gowa' => [], 'waba' => []]);
        config()->set('gateway.seed.credentials', []);

        $this->seed(GatewayHubManagementSeeder::class);

        $shelf = ClientApplication::query()->where('slug', 'web-shelf')->sole();
        RoutingPolicy::query()->create([
            'client_application_id' => $shelf->id,
            'operation' => 'number_check',
            'key' => 'default',
            'name' => 'Legacy shelf number check',
            'purpose' => null,
            'is_default' => true,
            'is_active' => true,
        ]);

        $this->seed(GatewayHubManagementSeeder::class);

        $this->assertFalse(
            RoutingPolicy::query()
                ->where('client_application_id', $shelf->id)
                ->where('operation', 'number_check')
                ->exists(),
        );
        $this->assertTrue(
            RoutingPolicy::query()
                ->where('client_application_id', ClientApplication::query()->where('slug', 'web-cesa')->value('id'))
                ->where('operation', 'number_check')
                ->where('key', 'lead-number-check')
                ->exists(),
        );
    }
}
