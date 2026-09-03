<?php

namespace App\Filament\Resources\RoutingPolicies\Widgets;

use App\Models\RoutingPolicy;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class RoutingPolicyStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $total = RoutingPolicy::query()->count();
        $active = RoutingPolicy::query()->where('is_active', true)->count();
        $defaults = RoutingPolicy::query()->where('is_default', true)->count();

        return [
            Stat::make('Aturan rute', $total)
                ->description('Semua urutan pengiriman')
                ->color('gray'),
            Stat::make('Aktif', $active)
                ->description('Dipakai saat mengirim')
                ->color('success'),
            Stat::make('Default', $defaults)
                ->description('Dipakai jika kunci rute tidak cocok')
                ->color('info'),
        ];
    }
}
