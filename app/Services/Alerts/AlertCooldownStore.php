<?php

namespace App\Services\Alerts;

use Illuminate\Support\Facades\Cache;

final class AlertCooldownStore
{
    public function cacheKey(int $providerAccountId): string
    {
        return "alert:cooldown:provider:{$providerAccountId}";
    }

    public function isCoolingDown(int $providerAccountId): bool
    {
        return Cache::has($this->cacheKey($providerAccountId));
    }

    public function hit(int $providerAccountId, int $cooldownSeconds): void
    {
        if ($cooldownSeconds === 0) {
            return;
        }

        Cache::put($this->cacheKey($providerAccountId), true, $cooldownSeconds);
    }
}
