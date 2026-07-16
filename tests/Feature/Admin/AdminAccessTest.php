<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_the_filament_login_page(): void
    {
        $this->get('/panel')
            ->assertRedirect('/panel/login');
    }

    public function test_active_administrator_can_open_the_admin_panel(): void
    {
        $administrator = $this->createUser(isAdmin: true, isActive: true);

        $this->actingAs($administrator)
            ->get('/panel')
            ->assertOk();
    }

    public function test_non_administrator_is_denied_access_to_the_admin_panel(): void
    {
        $user = $this->createUser(isAdmin: false, isActive: true);

        $this->actingAs($user)
            ->get('/panel')
            ->assertForbidden();
    }

    public function test_inactive_administrator_is_denied_access_to_the_admin_panel(): void
    {
        $administrator = $this->createUser(isAdmin: true, isActive: false);

        $this->actingAs($administrator)
            ->get('/panel')
            ->assertForbidden();
    }

    public function test_public_admin_registration_is_not_available(): void
    {
        $this->get('/panel/register')
            ->assertNotFound();
    }

    private function createUser(bool $isAdmin, bool $isActive): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'is_admin' => $isAdmin,
            'is_active' => $isActive,
        ])->save();

        return $user->fresh();
    }
}
