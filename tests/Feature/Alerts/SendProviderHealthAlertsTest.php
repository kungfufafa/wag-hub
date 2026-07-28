<?php

namespace Tests\Feature\Alerts;

use App\Events\ProviderHealthChanged;
use App\Jobs\DeliverProviderHealthAlert;
use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Notifications\ProviderHealthChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class SendProviderHealthAlertsTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_listener_delivers_sync_for_enabled_channels_and_skips_cooldown(): void
    {
        config()->set('gateway.alerts.delivery', 'sync');

        Notification::fake();

        $settings = AlertSetting::current();
        $settings->forceFill([
            'is_enabled' => true,
            'cooldown_seconds' => 300,
            'smtp_host' => 'smtp.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_from_address' => 'alerts@test.local',
            'smtp_from_name' => 'Gateway Alerts',
            'telegram_bot_token' => '1:token',
        ])->save();

        $admin = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'email' => 'admin@test.local',
        ]);
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

        $this->assertSame(2, AlertDelivery::query()->where('status', 'sent')->count());
        $this->assertSame(0, AlertDelivery::query()->where('status', 'pending')->count());
        Notification::assertSentToTimes($admin, ProviderHealthChangedNotification::class, 2);

        // Second event during cooldown → skipped rows, no new sends
        Notification::fake();
        event($event);
        $this->assertSame(2, AlertDelivery::query()->where('status', 'skipped_cooldown')->count());
        Notification::assertNothingSent();
    }

    public function test_listener_queues_jobs_when_async_delivery_is_enabled(): void
    {
        config()->set('gateway.alerts.delivery', 'async');
        Queue::fake();

        AlertSetting::current()->forceFill([
            'is_enabled' => true,
            'cooldown_seconds' => 300,
            'telegram_bot_token' => '1:token',
        ])->save();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $admin->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '111',
            'email_enabled' => false,
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-async-fanout', [
            'base_url' => 'https://waha-async-fanout.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);

        event(new ProviderHealthChanged(
            providerAccountId: $provider['id'],
            providerName: 'Waha Async',
            providerSlug: 'waha-async-fanout',
            providerDriver: 'waha',
            providerUuid: (string) \Illuminate\Support\Facades\DB::table('provider_accounts')->where('id', $provider['id'])->value('uuid'),
            fromStatus: 'healthy',
            toStatus: 'degraded',
            consecutiveFailures: 1,
            circuitOpenUntilIso: null,
            errorSummary: 'timeout',
        ));

        Queue::assertPushed(DeliverProviderHealthAlert::class, 1);
        $this->assertSame(1, AlertDelivery::query()->where('status', 'pending')->count());
    }

    public function test_listener_noops_when_master_switch_off(): void
    {
        config()->set('gateway.alerts.delivery', 'sync');
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

    public function test_listener_bypasses_cooldown_for_escalation_and_recovery(): void
    {
        config()->set('gateway.alerts.delivery', 'sync');
        Notification::fake();

        AlertSetting::current()->forceFill([
            'is_enabled' => true,
            'cooldown_seconds' => 300,
            'telegram_bot_token' => '1:token',
        ])->save();

        $admin = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $admin->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '111',
            'email_enabled' => false,
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-cooldown-bypass', [
            'base_url' => 'https://waha-cooldown-bypass.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);
        $uuid = (string) \Illuminate\Support\Facades\DB::table('provider_accounts')->where('id', $provider['id'])->value('uuid');

        event(new ProviderHealthChanged(
            providerAccountId: $provider['id'],
            providerName: 'Waha Cooldown',
            providerSlug: 'waha-cooldown-bypass',
            providerDriver: 'waha',
            providerUuid: $uuid,
            fromStatus: 'healthy',
            toStatus: 'degraded',
            consecutiveFailures: 1,
            circuitOpenUntilIso: null,
            errorSummary: 'first',
        ));

        $this->assertSame(1, AlertDelivery::query()->where('status', 'sent')->where('to_status', 'degraded')->count());

        Notification::fake();
        event(new ProviderHealthChanged(
            providerAccountId: $provider['id'],
            providerName: 'Waha Cooldown',
            providerSlug: 'waha-cooldown-bypass',
            providerDriver: 'waha',
            providerUuid: $uuid,
            fromStatus: 'degraded',
            toStatus: 'unavailable',
            consecutiveFailures: 3,
            circuitOpenUntilIso: now()->addMinutes(5)->toIso8601String(),
            errorSummary: 'escalated',
        ));

        $this->assertSame(1, AlertDelivery::query()->where('status', 'sent')->where('to_status', 'unavailable')->count());
        Notification::assertSentToTimes($admin, ProviderHealthChangedNotification::class, 1);

        Notification::fake();
        event(new ProviderHealthChanged(
            providerAccountId: $provider['id'],
            providerName: 'Waha Cooldown',
            providerSlug: 'waha-cooldown-bypass',
            providerDriver: 'waha',
            providerUuid: $uuid,
            fromStatus: 'unavailable',
            toStatus: 'healthy',
            consecutiveFailures: 0,
            circuitOpenUntilIso: null,
            errorSummary: null,
        ));

        $this->assertSame(1, AlertDelivery::query()->where('status', 'sent')->where('to_status', 'healthy')->count());
        Notification::assertSentToTimes($admin, ProviderHealthChangedNotification::class, 1);
    }
}
