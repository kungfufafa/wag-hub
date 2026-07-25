<?php

namespace App\Services\Alerts;

use App\Events\ProviderHealthChanged;
use App\Jobs\DeliverProviderHealthAlert;
use App\Models\AlertDelivery;
use App\Models\AlertSetting;
use App\Models\User;

final class SendProviderHealthAlerts
{
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

        if ($this->cooldownStore->isCoolingDown($event->providerAccountId)) {
            foreach ($eligibleChannels as [$user, $channel]) {
                $this->createDelivery($event, $user, $channel, 'skipped_cooldown');
            }

            return;
        }

        $pendingCreated = 0;

        foreach ($eligibleChannels as [$user, $channel]) {
            $delivery = $this->createDelivery($event, $user, $channel, 'pending');
            DeliverProviderHealthAlert::dispatch($delivery->id);
            $pendingCreated++;
        }

        if ($pendingCreated > 0) {
            $this->cooldownStore->hit($event->providerAccountId, (int) $settings->cooldown_seconds);
        }
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
