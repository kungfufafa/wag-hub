<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\ManageAlertSettings;
use App\Models\AlertSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_and_save_alert_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);

        $this->get('/panel/alert-settings')->assertOk();

        Livewire::test(ManageAlertSettings::class)
            ->fillForm([
                'is_enabled' => true,
                'cooldown_seconds' => 120,
                'smtp_host' => 'smtp.example.test',
                'smtp_port' => 587,
                'smtp_from_address' => 'alerts@example.test',
                'telegram_bot_token' => '1:NEWTOKEN',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = AlertSetting::current()->fresh();
        $this->assertTrue($settings->is_enabled);
        $this->assertSame(120, $settings->cooldown_seconds);
        $this->assertSame('1:NEWTOKEN', $settings->telegram_bot_token);
    }

    public function test_blank_secret_fields_keep_existing_values(): void
    {
        AlertSetting::current()->forceFill([
            'telegram_bot_token' => 'keep-me',
            'smtp_password' => 'keep-pass',
        ])->save();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        $this->actingAs($admin);

        Livewire::test(ManageAlertSettings::class)
            ->fillForm([
                'telegram_bot_token' => '',
                'smtp_password' => '',
                'smtp_host' => 'smtp.example.test',
                'smtp_from_address' => 'a@b.test',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $settings = AlertSetting::current()->fresh();
        $this->assertSame('keep-me', $settings->telegram_bot_token);
        $this->assertSame('keep-pass', $settings->smtp_password);
    }
}
