<?php

namespace Tests\Feature\Alerts;

use App\Events\ProviderHealthChanged;
use App\Jobs\DeliverProviderHealthAlert;
use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Models\User;
use App\Models\UserAlertPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class SendProviderHealthAlertsTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_listener_queues_jobs_for_enabled_channels_and_skips_cooldown(): void
    {
        Queue::fake();

        $settings = AlertSetting::current();
        $settings->forceFill([
            'is_enabled' => true,
            'cooldown_seconds' => 300,
            'smtp_host' => 'smtp.test',
            'smtp_port' => 587,
            'smtp_from_address' => 'alerts@test.local',
            'telegram_bot_token' => '1:token',
        ])->save();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $admin->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '111',
            'email_enabled' => true,
            'email_address' => null,
        ]);
        User::factory()->create(['is_admin' => true, 'is_active' => false]); // ignored
        $inactivePrefAdmin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $inactivePrefAdmin->id,
            'telegram_enabled' => false,
            'email_enabled' => false,
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-fanout', [
            'base_url' => 'https://waha-fanout.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);

        $event = new ProviderHealthChanged(
            providerAccountId: $provider['id'],
            providerName: 'Waha Fanout',
            providerSlug: 'waha-fanout',
            providerDriver: 'waha',
            providerUuid: (string) \Illuminate\Support\Facades\DB::table('provider_accounts')->where('id', $provider['id'])->value('uuid'),
            fromStatus: 'healthy',
            toStatus: 'degraded',
            consecutiveFailures: 1,
            circuitOpenUntilIso: null,
            errorSummary: 'timeout',
        );

        event($event);

        Queue::assertPushed(DeliverProviderHealthAlert::class, 2);
        $this->assertSame(2, AlertDelivery::query()->where('status', 'pending')->count());

        // Second event during cooldown → skipped rows, no new jobs
        Queue::fake();
        event($event);
        Queue::assertNothingPushed();
        $this->assertSame(2, AlertDelivery::query()->where('status', 'skipped_cooldown')->count());
    }

    public function test_listener_noops_when_master_switch_off(): void
    {
        Queue::fake();
        AlertSetting::current()->forceFill(['is_enabled' => false])->save();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $admin->id,
            'email_enabled' => true,
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-off', [
            'base_url' => 'https://waha-off.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);

        event(new ProviderHealthChanged(
            providerAccountId: $provider['id'],
            providerName: 'Off',
            providerSlug: 'waha-off',
            providerDriver: 'waha',
            providerUuid: (string) \Illuminate\Support\Facades\DB::table('provider_accounts')->where('id', $provider['id'])->value('uuid'),
            fromStatus: 'healthy',
            toStatus: 'degraded',
            consecutiveFailures: 1,
            circuitOpenUntilIso: null,
            errorSummary: null,
        ));

        Queue::assertNothingPushed();
        $this->assertSame(0, AlertDelivery::query()->count());
    }
}
