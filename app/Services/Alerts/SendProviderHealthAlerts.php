<?php

namespace App\Services\Alerts;

use App\Events\ProviderHealthChanged;
use App\Jobs\DeliverProviderHealthAlert;
use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendProviderHealthAlerts
{
    /**
     * @var array<string, int>
     */
    private const STATUS_SEVERITY = [
        'unknown' => 0,
        'healthy' => 0,
        'degraded' => 1,
        'unavailable' => 2,
    ];

    public function __construct(
        private readonly AlertCooldownStore $cooldownStore,
    ) {}

    public function handle(ProviderHealthChanged $event): void
    {
        $settings = AlertSetting::current();

        if (! $settings->is_enabled) {
            return;
        }

        $eligibleChannels = $this->eligibleChannels();

        if ($eligibleChannels === []) {
            return;
        }

        if (
            $this->cooldownStore->isCoolingDown($event->providerAccountId)
            && ! $this->shouldBypassCooldown($event)
        ) {
            foreach ($eligibleChannels as [$user, $channel]) {
                $this->createDelivery($event, $user, $channel, 'skipped_cooldown');
            }

            return;
        }

        $pendingCreated = 0;
        $async = (string) config('gateway.alerts.delivery', 'sync') === 'async';

        foreach ($eligibleChannels as [$user, $channel]) {
            $delivery = $this->createDelivery($event, $user, $channel, 'pending');
            $this->dispatchDelivery($delivery->id, $async);
            $pendingCreated++;
        }

        if ($pendingCreated > 0) {
            $this->cooldownStore->hit($event->providerAccountId, (int) $settings->cooldown_seconds);
        }
    }

    private function shouldBypassCooldown(ProviderHealthChanged $event): bool
    {
        // Always notify recovery even inside the cooldown window.
        if ($event->toStatus === 'healthy') {
            return true;
        }

        $from = self::STATUS_SEVERITY[$event->fromStatus] ?? 0;
        $to = self::STATUS_SEVERITY[$event->toStatus] ?? 0;

        // Only escalate through cooldown when already unhealthy and getting worse
        // (e.g. degraded → unavailable). healthy → degraded stays subject to cooldown.
        return $from >= 1 && $to > $from;
    }

    private function dispatchDelivery(int $deliveryId, bool $async): void
    {
        if ($async) {
            DeliverProviderHealthAlert::dispatch($deliveryId);

            return;
        }

        // Web requests: send after HTTP response so message API latency is unaffected.
        // Console/tests: no response cycle — run immediately (still no queue worker).
        if (! app()->runningInConsole()) {
            DeliverProviderHealthAlert::dispatch($deliveryId)->afterResponse();

            return;
        }

        try {
            DeliverProviderHealthAlert::dispatchSync($deliveryId);
        } catch (Throwable $exception) {
            $this->ensureDeliveryFailed($deliveryId, $exception);
            Log::warning('Provider health alert sync delivery failed.', [
                'alert_delivery_id' => $deliveryId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function ensureDeliveryFailed(int $deliveryId, Throwable $exception): void
    {
        $delivery = AlertDelivery::query()->find($deliveryId);

        if ($delivery === null || $delivery->status !== 'pending') {
            return;
        }

        $reason = trim($exception->getMessage());
        $reason = $reason !== '' ? $reason : 'Delivery failed';
        $reason = preg_replace('/bot\d+:[A-Za-z0-9_-]+/', 'bot[redacted]', $reason) ?? $reason;

        $delivery->forceFill([
            'status' => 'failed',
            'failure_reason' => mb_substr($reason, 0, 1000),
        ])->save();
    }

    /**
     * @return list<array{0: User, 1: string}>
     */
    private function eligibleChannels(): array
    {
        $admins = User::query()
            ->where('is_admin', true)
            ->where('is_active', true)
            ->with('alertPreference')
            ->get();

        $channels = [];

        foreach ($admins as $user) {
            $preference = $user->alertPreference;

            if ($preference === null) {
                continue;
            }

            if ($preference->telegram_enabled && filled($preference->telegram_chat_id)) {
                $channels[] = [$user, 'telegram'];
            }

            if ($preference->email_enabled && filled($preference->email_address ?? $user->email)) {
                $channels[] = [$user, 'email'];
            }
        }

        return $channels;
    }

    private function createDelivery(
        ProviderHealthChanged $event,
        User $user,
        string $channel,
        string $status,
    ): AlertDelivery {
        return AlertDelivery::query()->create([
            'provider_account_id' => $event->providerAccountId,
            'user_id' => $user->id,
            'channel' => $channel,
            'from_status' => $event->fromStatus,
            'to_status' => $event->toStatus,
            'consecutive_failures' => $event->consecutiveFailures,
            'circuit_open_until' => $event->circuitOpenUntilIso,
            'error_summary' => $event->errorSummary,
            'status' => $status,
        ]);
    }
}
