<?php

namespace App\Filament\Resources\ClientApplications\Widgets;

use App\Models\ClientApplication;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ClientApplicationStats extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $total = ClientApplication::query()->count();
        $active = ClientApplication::query()->where('is_active', true)->count();

        return [
            Stat::make('Aplikasi', $total)
                ->description('Semua aplikasi sumber')
                ->color('gray'),
            Stat::make('Aktif', $active)
                ->description('Boleh mengirim pesan')
                ->color('success'),
            Stat::make('Nonaktif', $total - $active)
                ->description('Pengiriman dihentikan')
                ->color('warning'),
        ];
    }
}
