<?php

namespace Tests\Feature\Alerts;

use App\Models\AlertSetting;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlertSettingsModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_alert_setting_secrets_are_encrypted_at_rest(): void
    {
        $settings = AlertSetting::current();
        $settings->forceFill([
            'smtp_password' => 'smtp-secret',
            'telegram_bot_token' => '123:ABC',
        ])->save();

        $raw = DB::table('alert_settings')->where('id', $settings->id)->first();
        $this->assertNotSame('smtp-secret', $raw->smtp_password);
        $this->assertNotSame('123:ABC', $raw->telegram_bot_token);

        $fresh = AlertSetting::query()->findOrFail($settings->id);
        $this->assertSame('smtp-secret', $fresh->smtp_password);
        $this->assertSame('123:ABC', $fresh->telegram_bot_token);
    }

    public function test_user_can_have_one_alert_preference(): void
    {
        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);

        $pref = UserAlertPreference::query()->create([
            'user_id' => $user->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '999',
            'email_enabled' => true,
            'email_address' => null,
        ]);

        $this->assertTrue($user->fresh()->alertPreference->is($pref));
        $this->assertSame('999', $user->alertPreference->telegram_chat_id);
    }
}
