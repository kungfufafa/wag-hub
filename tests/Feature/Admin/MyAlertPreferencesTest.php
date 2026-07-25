<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\MyAlertPreferences;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyAlertPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_own_alert_preferences(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);
        $this->get('/panel/my-alert-preferences')->assertOk();

        Livewire::test(MyAlertPreferences::class)
            ->fillForm([
                'telegram_enabled' => true,
                'telegram_chat_id' => '4242',
                'email_enabled' => true,
                'email_address' => 'me@ops.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $pref = $admin->fresh()->alertPreference;
        $this->assertTrue($pref->telegram_enabled);
        $this->assertSame('4242', $pref->telegram_chat_id);
        $this->assertSame('me@ops.test', $pref->email_address);
    }
}
