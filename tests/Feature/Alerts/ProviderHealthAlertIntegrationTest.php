<?php

namespace Tests\Feature\Alerts;

use App\Domain\Delivery\ProviderResult;
use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Models\ProviderAccount;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Services\ProviderHealthRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;

class ProviderHealthAlertIntegrationTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_health_change_to_unavailable_and_recovery_delivers_telegram_alerts(): void
    {
        config()->set('gateway.alerts.delivery', 'sync');
        config()->set('gateway.provider_health.failure_threshold', 1);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

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

        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-e2e-alert', [
                'base_url' => 'https://waha-e2e-alert.test',
                'api_key' => 'secret',
                'session' => 'default',
            ])['id'],
        );

        $recorder = app(ProviderHealthRecorder::class);

        $recorder->record($provider, ProviderResult::providerFailed(errorMessage: 'provider-down'));

        $this->assertDatabaseHas('alert_deliveries', [
            'channel' => 'telegram',
            'to_status' => 'unavailable',
            'status' => 'sent',
        ]);

        Cache::flush();

        $recorder->record($provider->fresh(), ProviderResult::accepted());

        $this->assertDatabaseHas('alert_deliveries', [
            'channel' => 'telegram',
            'from_status' => 'unavailable',
            'to_status' => 'healthy',
            'status' => 'sent',
        ]);

        $this->assertSame(2, AlertDelivery::query()->where('status', 'sent')->count());
        Http::assertSentCount(2);
    }

    public function test_message_rejection_health_change_delivers_telegram_alert(): void
    {
        config()->set('gateway.alerts.delivery', 'sync');
        config()->set('gateway.provider_health.failure_threshold', 1);

        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

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

        $provider = ProviderAccount::query()->findOrFail(
            $this->createProviderAccount('waha', 'waha-reject-e2e-alert', [
                'base_url' => 'https://waha-reject-e2e-alert.test',
                'api_key' => 'secret',
                'session' => 'default',
            ])['id'],
        );

        app(ProviderHealthRecorder::class)->record(
            $provider,
            ProviderResult::rejected(
                httpStatus: 422,
                errorCode: 'invalid_message',
                errorMessage: 'Invalid chatId',
            ),
        );

        $this->assertDatabaseHas('alert_deliveries', [
            'channel' => 'telegram',
            'from_status' => 'healthy',
            'to_status' => 'unavailable',
            'status' => 'sent',
        ]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }
}
