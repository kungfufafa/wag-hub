<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateGatewayAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_gateway_administrator_without_echoing_the_password(): void
    {
        $password = 'Sangat-Rahasia-123!';

        $this->artisan('gateway:create-admin', [
            '--name' => 'Gateway Operator',
            '--email' => 'operator@example.test',
            '--password' => $password,
        ])
            ->expectsOutputToContain('operator@example.test')
            ->doesntExpectOutputToContain($password)
            ->assertSuccessful();

        $administrator = User::query()->where('email', 'operator@example.test')->sole();

        $this->assertTrue($administrator->is_admin);
        $this->assertTrue($administrator->is_active);
        $this->assertTrue(Hash::check($password, $administrator->password));
    }

    public function test_it_refuses_to_replace_an_existing_user(): void
    {
        User::factory()->create(['email' => 'existing@example.test']);

        $this->artisan('gateway:create-admin', [
            '--name' => 'Replacement',
            '--email' => 'existing@example.test',
            '--password' => 'Replacement-Secret-123!',
        ])->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
