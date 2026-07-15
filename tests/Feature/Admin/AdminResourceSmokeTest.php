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
    public function test_configuration_create_pages_show_a_next_step_sidebar(string $path, string $heading): void
    {
        $administrator = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
        ]);

        $this->actingAs($administrator)
            ->get($path)
            ->assertOk()
            ->assertSee($heading);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function configurationCreatePages(): array
    {
        return [
            'client application' => ['/admin/client-applications/create', 'Langkah berikutnya'],
            'provider account' => ['/admin/provider-accounts/create', 'Sebelum menyimpan'],
            'routing policy' => ['/admin/routing-policies/create', 'Urutan pengiriman'],
        ];
    }
}
