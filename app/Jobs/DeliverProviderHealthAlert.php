<?php

namespace App\Jobs;

use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Notifications\ProviderHealthChangedNotification;
use App\Services\Alerts\AlertMailerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class DeliverProviderHealthAlert implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 60;

    public function __construct(public int $alertDeliveryId) {}

    public function handle(): void
    {
        $delivery = AlertDelivery::query()->find($this->alertDeliveryId);

        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }

        $user = $delivery->user;

        if ($user === null) {
            $this->markFailed($delivery, 'User penerima tidak ditemukan');

            return;
        }

        $channel = $delivery->channel === 'email' ? 'mail' : $delivery->channel;
        $mailerFactory = app(AlertMailerFactory::class);

        if ($channel === 'mail') {
            $settings = AlertSetting::current();

            if (! $mailerFactory->isConfigured($settings)) {
                $this->markFailed($delivery, 'SMTP belum dikonfigurasi');

                return;
            }

            $mailerFactory->registerFromSettings($settings);
        }

        if ($channel === 'telegram' && ! filled(AlertSetting::current()->telegram_bot_token)) {
            $this->markFailed($delivery, 'Telegram bot token belum dikonfigurasi');

            return;
        }

        $circuitOpenUntilIso = $delivery->circuit_open_until?->toIso8601String();

        $user->notify(new ProviderHealthChangedNotification(
            channel: $channel,
            providerAccountId: $delivery->provider_account_id,
            fromStatus: $delivery->from_status,
            toStatus: $delivery->to_status,
            consecutiveFailures: (int) $delivery->consecutive_failures,
            circuitOpenUntilIso: $circuitOpenUntilIso,
            errorSummary: $delivery->error_summary,
        ));

        $delivery->forceFill([
            'status' => 'sent',
            'sent_at' => now(),
            'failure_reason' => null,
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        $delivery = AlertDelivery::query()->find($this->alertDeliveryId);

        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }

        $this->markFailed($delivery, $this->sanitizeFailureReason($e));
    }

    private function markFailed(AlertDelivery $delivery, string $reason): void
    {
        $delivery->forceFill([
            'status' => 'failed',
            'failure_reason' => $reason,
        ])->save();
    }

    private function sanitizeFailureReason(?Throwable $e): string
    {
        $message = trim((string) ($e?->getMessage() ?: 'Delivery failed'));
        $message = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 1000);
    }
}
