<?php

namespace Tests\Feature\Alerts;

use App\Jobs\DeliverProviderHealthAlert;
use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Models\User;
use App\Models\UserAlertPreference;
use App\Notifications\ProviderHealthChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\BuildsGatewayFixtures;
use Tests\TestCase;
use Throwable;

class DeliverProviderHealthAlertTest extends TestCase
{
    use BuildsGatewayFixtures;
    use RefreshDatabase;

    public function test_telegram_delivery_marks_sent_and_posts_to_bot_api(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        AlertSetting::current()->forceFill([
            'is_enabled' => true,
            'telegram_bot_token' => '99:tok',
        ])->save();

        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $user->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '555',
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-tg', [
            'base_url' => 'https://waha-tg.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);
        $uuid = (string) \DB::table('provider_accounts')->where('id', $provider['id'])->value('uuid');

        $delivery = AlertDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'provider_account_id' => $provider['id'],
            'user_id' => $user->id,
            'channel' => 'telegram',
            'from_status' => 'healthy',
            'to_status' => 'unavailable',
            'consecutive_failures' => 3,
            'circuit_open_until' => now()->addMinutes(5),
            'error_summary' => 'down',
            'status' => 'pending',
        ]);

        (new DeliverProviderHealthAlert($delivery->id))->handle();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org/bot99:tok/sendMessage')
            && $request['chat_id'] === '555'
            && str_contains($request['text'], 'UNAVAILABLE')
            && str_contains($request['text'], $uuid));

        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->sent_at);
    }

    public function test_email_delivery_uses_runtime_smtp_mailer_not_log_mailer(): void
    {
        Notification::fake();

        $globalFromBefore = config('mail.from');

        AlertSetting::current()->forceFill([
            'is_enabled' => true,
            'smtp_host' => 'smtp.alerts.test',
            'smtp_port' => 587,
            'smtp_username' => 'u',
            'smtp_password' => 'p',
            'smtp_encryption' => 'tls',
            'smtp_from_address' => 'alerts@gateway.test',
            'smtp_from_name' => 'WAG Hub',
        ])->save();

        $user = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'email' => 'admin@example.test',
        ]);
        UserAlertPreference::query()->create([
            'user_id' => $user->id,
            'email_enabled' => true,
            'email_address' => 'ops@example.test',
        ]);

        $provider = $this->createProviderAccount('fonnte', 'fonnte-mail', [
            'endpoint' => 'https://fonnte-mail.test/send',
            'token' => 't',
        ]);

        $delivery = AlertDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'provider_account_id' => $provider['id'],
            'user_id' => $user->id,
            'channel' => 'email',
            'from_status' => 'degraded',
            'to_status' => 'healthy',
            'consecutive_failures' => 0,
            'circuit_open_until' => null,
            'error_summary' => null,
            'status' => 'pending',
        ]);

        (new DeliverProviderHealthAlert($delivery->id))->handle();

        Notification::assertSentTo(
            $user,
            ProviderHealthChangedNotification::class,
            function ($notification, $channels) use ($user) {
                if ($channels !== ['mail']) {
                    return false;
                }

                $mail = $notification->toMail($user);

                return ($mail->from[0] ?? null) === 'alerts@gateway.test'
                    && ($mail->from[1] ?? null) === 'WAG Hub';
            },
        );
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame('smtp.alerts.test', config('mail.mailers.provider_health_alerts.host'));
        $this->assertSame('smtp', config('mail.mailers.provider_health_alerts.transport'));
        $this->assertSame($globalFromBefore, config('mail.from'));
    }

    public function test_telegram_http_failure_marks_delivery_failed_with_reason(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'server error'], 500),
        ]);

        AlertSetting::current()->forceFill([
            'is_enabled' => true,
            'telegram_bot_token' => '99:tok',
        ])->save();

        $user = User::factory()->create(['is_admin' => true, 'is_active' => true]);
        UserAlertPreference::query()->create([
            'user_id' => $user->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '555',
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-tg-fail', [
            'base_url' => 'https://waha-tg-fail.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);

        $delivery = AlertDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'provider_account_id' => $provider['id'],
            'user_id' => $user->id,
            'channel' => 'telegram',
            'from_status' => 'healthy',
            'to_status' => 'unavailable',
            'consecutive_failures' => 1,
            'circuit_open_until' => null,
            'error_summary' => 'down',
            'status' => 'pending',
        ]);

        $job = new DeliverProviderHealthAlert($delivery->id);
        $thrown = null;

        try {
            $job->handle();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(Throwable::class, $thrown, 'Expected telegram delivery to throw');
        $job->failed($thrown);

        $fresh = $delivery->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->failure_reason);
        $this->assertNotSame('', trim((string) $fresh->failure_reason));
    }

    public function test_telegram_failure_does_not_block_separate_email_delivery(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500),
        ]);

        AlertSetting::current()->forceFill([
            'is_enabled' => true,
            'telegram_bot_token' => '99:tok',
            'smtp_host' => 'smtp.alerts.test',
            'smtp_port' => 587,
            'smtp_username' => 'u',
            'smtp_password' => 'p',
            'smtp_encryption' => 'tls',
            'smtp_from_address' => 'alerts@gateway.test',
            'smtp_from_name' => 'WAG Hub',
        ])->save();

        $user = User::factory()->create([
            'is_admin' => true,
            'is_active' => true,
            'email' => 'admin@example.test',
        ]);
        UserAlertPreference::query()->create([
            'user_id' => $user->id,
            'telegram_enabled' => true,
            'telegram_chat_id' => '555',
            'email_enabled' => true,
            'email_address' => 'ops@example.test',
        ]);

        $provider = $this->createProviderAccount('waha', 'waha-iso', [
            'base_url' => 'https://waha-iso.test',
            'api_key' => 'x',
            'session' => 'default',
        ]);

        $telegramDelivery = AlertDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'provider_account_id' => $provider['id'],
            'user_id' => $user->id,
            'channel' => 'telegram',
            'from_status' => 'healthy',
            'to_status' => 'unavailable',
            'consecutive_failures' => 2,
            'circuit_open_until' => null,
            'error_summary' => 'down',
            'status' => 'pending',
        ]);

        $emailDelivery = AlertDelivery::query()->create([
            'uuid' => (string) Str::uuid(),
            'provider_account_id' => $provider['id'],
            'user_id' => $user->id,
            'channel' => 'email',
            'from_status' => 'healthy',
            'to_status' => 'unavailable',
            'consecutive_failures' => 2,
            'circuit_open_until' => null,
            'error_summary' => 'down',
            'status' => 'pending',
        ]);

        $telegramJob = new DeliverProviderHealthAlert($telegramDelivery->id);
        $thrown = null;

        try {
            $telegramJob->handle();
        } catch (Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(Throwable::class, $thrown, 'Expected telegram delivery to throw');
        $telegramJob->failed($thrown);

        Notification::fake();
        (new DeliverProviderHealthAlert($emailDelivery->id))->handle();

        $this->assertSame('failed', $telegramDelivery->fresh()->status);
        $this->assertSame('sent', $emailDelivery->fresh()->status);
        Notification::assertSentTo($user, ProviderHealthChangedNotification::class);
    }
}
