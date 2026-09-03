<?php

namespace App\Filament\Resources\ProviderAccounts\Widgets;

use App\Models\ProviderAccount;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProviderAccountStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $total = ProviderAccount::query()->count();
        $healthy = ProviderAccount::query()->where('health_status', 'healthy')->count();
        $problem = ProviderAccount::query()
            ->whereIn('health_status', ['degraded', 'unavailable'])
            ->count();

        return [
            Stat::make('Akun provider', $total)
                ->description('WAHA, Fonnte, GOWA, WABA')
                ->color('gray'),
            Stat::make('Sehat', $healthy)
                ->description('Siap dipakai pengiriman')
                ->color('success'),
            Stat::make('Perlu dicek', $problem)
                ->description('Menurun atau tidak tersedia')
                ->color($problem > 0 ? 'danger' : 'gray'),
        ];
    }
}
