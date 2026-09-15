<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminResourceSmokeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('resourcePages')]
    public function test_an_active_administrator_can_open_each_gateway_resource(string $path): void
    {
        $administrator = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->get($path)
            ->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function resourcePages(): array
    {
        return [
            'whatsapp inbox' => ['/panel/inbox'],
            'client applications' => ['/panel/client-applications'],
            'provider accounts' => ['/panel/provider-accounts'],
            'routing policies' => ['/panel/routing-policies'],
            'message ledger' => ['/panel/messages'],
            'number check ledger' => ['/panel/number-checks'],
            'alert settings' => ['/panel/alert-settings'],
            'my alert preferences' => ['/panel/my-alert-preferences'],
            'alert deliveries' => ['/panel/alert-deliveries'],
            'integration documentation' => ['/panel/dokumentasi-integrasi'],
        ];
    }

    public function test_sidebar_documentation_button_is_enabled_for_integration_docs(): void
    {
        $administrator = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->get('/panel')
            ->assertOk()
            ->assertSee('Dokumentasi Integrasi')
            ->assertSee('/panel/dokumentasi-integrasi', false);

        $this->actingAs($administrator)
            ->get('/panel/dokumentasi-integrasi')
            ->assertOk()
            ->assertSee('Siapkan aplikasi sumber')
            ->assertSee('Endpoint cepat')
            ->assertSee('POST /api/v1/messages')
            ->assertSee('Checklist')
            ->assertSee('GATEWAY_DISPATCH=async');
    }

    #[DataProvider('configurationCreatePages')]
    public function test_configuration_create_pages_show_a_next_step_sidebar(
        string $path,
        string $heading,
        string $resourceLabel,
    ): void {
        $administrator = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->get($path)
            ->assertOk()
            ->assertSee($heading)
            ->assertSee($resourceLabel)
            ->assertSee('fi-width-full', false);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function configurationCreatePages(): array
    {
        return [
            'client application' => ['/panel/client-applications/create', 'Langkah berikutnya', 'Aplikasi Klien'],
            'provider account' => ['/panel/provider-accounts/create', 'Sebelum menyimpan', 'Akun Provider'],
            'routing policy' => ['/panel/routing-policies/create', 'Ringkasan', 'Aturan Rute'],
        ];
    }

    #[DataProvider('configurationResourceFiles')]
    public function test_configuration_forms_use_the_workspace_sidebar_on_desktop(string $resourceFile): void
    {
        $source = file_get_contents(app_path($resourceFile));

        $this->assertIsString($source);
        $this->assertStringContainsString("'lg' => 3", $source);
        $this->assertStringContainsString("'lg' => 2", $source);
        $this->assertStringContainsString("'lg' => 1", $source);
        $this->assertStringNotContainsString("Grid::make(['2xl' => 3])", $source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function configurationResourceFiles(): array
    {
        return [
            'client application' => ['Filament/Resources/ClientApplications/ClientApplicationResource.php'],
            'provider account' => ['Filament/Resources/ProviderAccounts/ProviderAccountResource.php'],
            'routing policy' => ['Filament/Resources/RoutingPolicies/RoutingPolicyResource.php'],
        ];
    }
}
