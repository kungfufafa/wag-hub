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
            'client applications' => ['/admin/client-applications'],
            'provider accounts' => ['/admin/provider-accounts'],
            'routing policies' => ['/admin/routing-policies'],
            'message ledger' => ['/admin/messages'],
        ];
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
            'client application' => ['/admin/client-applications/create', 'Langkah berikutnya', 'Aplikasi Klien'],
            'provider account' => ['/admin/provider-accounts/create', 'Sebelum menyimpan', 'Akun Provider'],
            'routing policy' => ['/admin/routing-policies/create', 'Urutan pengiriman', 'Aturan Pengiriman'],
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
