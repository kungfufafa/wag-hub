<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ClientApplications\Pages\CreateClientApplication;
use App\Filament\Resources\ClientApplications\Pages\EditClientApplication;
use App\Filament\Resources\ClientApplications\Pages\ListClientApplications;
use App\Filament\Resources\ClientApplications\Widgets\ClientApplicationStats;
use App\Filament\Resources\ProviderAccounts\Pages\CreateProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\EditProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\ListProviderAccounts;
use App\Filament\Resources\ProviderAccounts\Widgets\ProviderAccountStats;
use App\Filament\Resources\RoutingPolicies\Pages\CreateRoutingPolicy;
use App\Filament\Resources\RoutingPolicies\Pages\EditRoutingPolicy;
use App\Filament\Resources\RoutingPolicies\Pages\ListRoutingPolicies;
use App\Filament\Resources\RoutingPolicies\Widgets\RoutingPolicyStats;
use App\Models\ApiCredential;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use App\Models\RoutingStep;
use App\Models\User;
use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ConfigurationResourceManagementTest extends TestCase
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
    }

    public function test_configuration_lists_show_summary_stats_and_records(): void
    {
        $application = $this->createClient('card-app');
        $provider = $this->createProvider('card-provider');
        $policy = $this->createPolicy($application, 'default', $provider);

        Livewire::test(ClientApplicationStats::class)
            ->assertSee('Aplikasi')
            ->assertSee('Aktif')
            ->assertSee('Nonaktif');

        Livewire::test(ListClientApplications::class)
            ->assertSee($application->name)
            ->assertSee('Nama');

        Livewire::test(ProviderAccountStats::class)
            ->assertSee('Akun provider')
            ->assertSee('Sehat')
            ->assertSee('Perlu dicek');

        Livewire::test(ListProviderAccounts::class)
            ->assertSee($provider->name)
            ->assertSee('WAHA');

        Livewire::test(RoutingPolicyStats::class)
            ->assertSee('Aturan rute')
            ->assertSee('Default');

        Livewire::test(ListRoutingPolicies::class)
            ->assertSee($policy->name)
            ->assertSee('Kirim pesan');
    }

    public function test_creating_a_client_application_generates_a_slug_from_the_name(): void
    {
        Livewire::test(CreateClientApplication::class)
            ->fillForm([
                'name' => 'Web Shelf',
                'rate_limit_per_minute' => 60,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('client_applications', [
            'name' => 'Web Shelf',
            'slug' => 'web-shelf',
            'is_active' => true,
        ]);
    }

    public function test_admin_can_delete_a_client_application_from_the_edit_page(): void
    {
        $application = $this->createClient('web-shelf');
        $issued = ApiCredential::issue($application, 'Pilot', ['messages:send']);
        $policy = $this->createPolicy($application, 'default');

        Livewire::test(EditClientApplication::class, ['record' => $application->getKey()])
            ->assertActionVisible('delete')
            ->assertActionVisible('createRoutingPolicy')
            ->callAction('delete')
            ->assertHasNoActionErrors()
            ->assertNotified('Aplikasi klien dihapus');

        $deleted = ClientApplication::withTrashed()->findOrFail($application->getKey());
        $this->assertTrue($deleted->trashed());
        $this->assertFalse($deleted->is_active);
        $this->assertSame('web-shelf--d'.$application->getKey(), $deleted->slug);
        $this->assertNotNull($issued->credential->fresh()->revoked_at);
        $this->assertTrue(RoutingPolicy::withTrashed()->findOrFail($policy->getKey())->trashed());

        Livewire::test(CreateClientApplication::class)
            ->fillForm([
                'name' => 'Web Shelf',
                'slug' => 'web-shelf',
                'rate_limit_per_minute' => 60,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('client_applications', [
            'slug' => 'web-shelf',
            'deleted_at' => null,
        ]);
    }

    public function test_admin_can_delete_a_client_application_from_the_list(): void
    {
        $application = $this->createClient('list-delete-app');

        Livewire::test(ListClientApplications::class)
            ->assertCanSeeTableRecords([$application])
            ->callTableAction('delete', $application)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Aplikasi klien dihapus');

        $this->assertTrue(ClientApplication::withTrashed()->findOrFail($application->getKey())->trashed());
    }

    public function test_deleting_a_client_application_soft_deletes_its_connections(): void
    {
        $application = $this->createClient('connected-app');
        $connection = $this->createConnection($application, 'support');

        $application->delete();

        $this->assertTrue(ClientApplication::withTrashed()->findOrFail($application->getKey())->trashed());
        $deleted = WhatsAppConnection::withTrashed()->findOrFail($connection->getKey());
        $this->assertTrue($deleted->trashed());
        $this->assertSame('support--d'.$connection->getKey(), $deleted->slug);
    }

    public function test_admin_can_delete_a_client_application_with_connections_from_the_ui(): void
    {
        $fromEdit = $this->createClient('edit-connected-app');
        $editConnection = $this->createConnection($fromEdit, 'support');
        $fromList = $this->createClient('list-connected-app');
        $listConnection = $this->createConnection($fromList, 'support');

        Livewire::test(EditClientApplication::class, ['record' => $fromEdit->getKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors()
            ->assertNotified('Aplikasi klien dihapus');

        Livewire::test(ListClientApplications::class)
            ->callTableAction('delete', $fromList)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Aplikasi klien dihapus');

        $this->assertTrue(ClientApplication::withTrashed()->findOrFail($fromEdit->getKey())->trashed());
        $this->assertTrue(ClientApplication::withTrashed()->findOrFail($fromList->getKey())->trashed());
        $this->assertTrue(WhatsAppConnection::withTrashed()->findOrFail($editConnection->getKey())->trashed());
        $this->assertTrue(WhatsAppConnection::withTrashed()->findOrFail($listConnection->getKey())->trashed());
    }

    public function test_deleted_client_application_tokens_are_rejected_by_the_api(): void
    {
        $client = $this->createClientApplication();
        $application = ClientApplication::query()->findOrFail($client['id']);

        $application->delete();

        $this->postMessage($client['token'], 'delete-client-'.Str::uuid(), $this->messagePayload())
            ->assertUnauthorized();
    }

    public function test_creating_a_provider_account_generates_a_slug_from_the_name(): void
    {
        Livewire::test(CreateProviderAccount::class)
            ->fillForm([
                'name' => 'Fonnte Cadangan',
                'driver' => 'fonnte',
                'configuration' => [
                    'endpoint' => 'https://api.fonnte.com/send',
                    'validate_endpoint' => 'https://api.fonnte.com/validate',
                    'token' => 'fonnte-secret',
                ],
                'is_active' => true,
                'timeout_seconds' => 15,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('provider_accounts', [
            'name' => 'Fonnte Cadangan',
            'slug' => 'fonnte-cadangan',
            'driver' => 'fonnte',
        ]);
    }

    public function test_admin_can_delete_a_provider_account_from_the_edit_page(): void
    {
        $application = $this->createClient('provider-delete-app');
        $provider = $this->createProvider('waha-primary');
        $policy = $this->createPolicy($application, 'default', $provider);

        Livewire::test(EditProviderAccount::class, ['record' => $provider->getKey()])
            ->assertActionVisible('delete')
            ->assertActionVisible('test')
            ->callAction('delete')
            ->assertHasNoActionErrors()
            ->assertNotified('Akun provider dihapus');

        $deleted = ProviderAccount::withTrashed()->findOrFail($provider->getKey());
        $this->assertTrue($deleted->trashed());
        $this->assertFalse($deleted->is_active);
        $this->assertSame('waha-primary--d'.$provider->getKey(), $deleted->slug);
        $this->assertDatabaseHas('routing_steps', [
            'routing_policy_id' => $policy->getKey(),
            'provider_account_id' => $provider->getKey(),
        ]);

        Livewire::test(CreateProviderAccount::class)
            ->fillForm([
                'name' => 'WAHA Primary',
                'slug' => 'waha-primary',
                'driver' => 'waha',
                'configuration' => [
                    'base_url' => 'https://waha.internal.example',
                    'session' => 'primary',
                    'api_key' => 'new-secret',
                ],
                'is_active' => true,
                'timeout_seconds' => 15,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('provider_accounts', [
            'slug' => 'waha-primary',
            'deleted_at' => null,
        ]);
    }

    public function test_admin_can_delete_a_provider_account_from_the_list(): void
    {
        $provider = $this->createProvider('list-delete-provider');

        Livewire::test(ListProviderAccounts::class)
            ->callTableAction('delete', $provider)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Akun provider dihapus');

        $this->assertTrue(ProviderAccount::withTrashed()->findOrFail($provider->getKey())->trashed());
    }

    public function test_admin_can_delete_a_routing_policy_from_the_edit_page(): void
    {
        $application = $this->createClient('route-delete-app');
        $policy = $this->createPolicy($application, 'default');

        Livewire::test(EditRoutingPolicy::class, ['record' => $policy->getKey()])
            ->assertActionVisible('delete')
            ->callAction('delete')
            ->assertHasNoActionErrors()
            ->assertNotified('Aturan rute dihapus');

        $deleted = RoutingPolicy::withTrashed()->findOrFail($policy->getKey());
        $this->assertTrue($deleted->trashed());
        $this->assertFalse($deleted->is_active);
        $this->assertSame('default--d'.$policy->getKey(), $deleted->key);

        $provider = ProviderAccount::query()->sole();

        Livewire::test(CreateRoutingPolicy::class)
            ->fillForm([
                'client_application_id' => $application->getKey(),
                'name' => 'Replacement route',
                'key' => 'default',
                'purpose' => 'notification',
                'is_default' => true,
                'is_active' => true,
                'steps' => [
                    ['provider_account_id' => $provider->getKey(), 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('routing_policies', [
            'client_application_id' => $application->getKey(),
            'key' => 'default',
            'deleted_at' => null,
        ]);
    }

    public function test_admin_can_delete_a_routing_policy_from_the_list(): void
    {
        $application = $this->createClient('list-route-app');
        $policy = $this->createPolicy($application, 'notifications');

        Livewire::test(ListRoutingPolicies::class)
            ->callTableAction('delete', $policy)
            ->assertHasNoTableActionErrors()
            ->assertNotified('Aturan rute dihapus');

        $this->assertTrue(RoutingPolicy::withTrashed()->findOrFail($policy->getKey())->trashed());
    }

    public function test_create_routing_policy_prefills_the_client_application_from_the_query_string(): void
    {
        $application = $this->createClient('prefill-app');

        Livewire::withQueryParams(['client_application_id' => (string) $application->getKey()])
            ->test(CreateRoutingPolicy::class)
            ->assertSchemaStateSet([
                'client_application_id' => $application->getKey(),
                'key' => 'default',
            ]);
    }

    public function test_create_routing_policy_page_keeps_advanced_matching_collapsed(): void
    {
        $this->get('/panel/routing-policies/create')
            ->assertOk()
            ->assertSee('Ringkasan')
            ->assertSee('Opsi lanjutan')
            ->assertSee('Semua aplikasi')
            ->assertDontSee('Cara kerja rute')
            ->assertDontSee('Tentukan aplikasi')
            ->assertDontSee('Harus sama dengan route_key')
            ->assertDontSee('Langkah aktif');
    }

    public function test_admin_can_create_a_route_with_name_and_providers_only(): void
    {
        $provider = $this->createProvider('simple-route-provider');

        Livewire::test(CreateRoutingPolicy::class)
            ->fillForm([
                'name' => 'Rute utama',
                'steps' => [
                    ['provider_account_id' => $provider->getKey()],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('routing_policies', [
            'name' => 'Rute utama',
            'client_application_id' => null,
            'operation' => 'message',
            'key' => 'default',
            'purpose' => null,
            'is_default' => false,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('routing_steps', [
            'provider_account_id' => $provider->getKey(),
            'position' => 1,
            'is_active' => true,
        ]);
    }

    private function createClient(string $slug): ClientApplication
    {
        return ClientApplication::forceCreate([
            'name' => Str::headline($slug),
            'slug' => $slug,
            'is_active' => true,
            'rate_limit_per_minute' => 60,
        ]);
    }

    private function createConnection(ClientApplication $application, string $slug): WhatsAppConnection
    {
        return WhatsAppConnection::forceCreate([
            'client_application_id' => $application->getKey(),
            'name' => Str::headline($slug),
            'slug' => $slug,
            'type' => 'provider_route',
            'status' => 'setup_required',
            'is_default' => true,
        ]);
    }

    private function createProvider(string $slug): ProviderAccount
    {
        return ProviderAccount::forceCreate([
            'name' => Str::headline($slug),
            'slug' => $slug,
            'driver' => 'waha',
            'configuration' => [
                'base_url' => 'https://waha.internal.example',
                'session' => 'primary',
                'api_key' => 'provider-secret',
            ],
            'is_active' => true,
            'health_status' => 'healthy',
            'timeout_seconds' => 15,
        ]);
    }

    private function createPolicy(
        ClientApplication $application,
        string $key,
        ?ProviderAccount $provider = null,
    ): RoutingPolicy {
        $provider ??= $this->createProvider('policy-'.$key.'-'.$application->getKey());

        $policy = RoutingPolicy::forceCreate([
            'client_application_id' => $application->getKey(),
            'name' => Str::headline($key),
            'operation' => 'message',
            'key' => $key,
            'purpose' => 'notification',
            'is_default' => true,
            'is_active' => true,
        ]);

        RoutingStep::query()->create([
            'routing_policy_id' => $policy->getKey(),
            'provider_account_id' => $provider->getKey(),
            'position' => 1,
            'is_active' => true,
        ]);

        return $policy;
    }
}
