<?php

namespace App\Filament\Widgets;

use App\Domain\Connection\ConnectionStatus;
use App\Models\WhatsAppConnection;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ConnectionHealthOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $total = WhatsAppConnection::query()->count();

        if ($total === 0) {
            return [
                Stat::make('Koneksi WhatsApp', '0')
                    ->description('Hubungkan WhatsApp dari menu Aplikasi')
                    ->color('gray'),
            ];
        }

        $ready = WhatsAppConnection::query()->where('status', ConnectionStatus::Ready->value)->count();
        $degraded = WhatsAppConnection::query()->where('status', ConnectionStatus::Degraded->value)->count();
        $needsAction = WhatsAppConnection::query()->whereIn('status', [
            ConnectionStatus::SetupRequired->value,
            ConnectionStatus::Connecting->value,
            ConnectionStatus::Error->value,
            ConnectionStatus::Disconnected->value,
        ])->count();

        return [
            Stat::make('Siap', $ready)
                ->description('Koneksi siap mengirim')
                ->color('success'),
            Stat::make('Terganggu', $degraded)
                ->description('Masih dapat mengirim dengan risiko')
                ->color('warning'),
            Stat::make('Perlu tindakan', $needsAction)
                ->description('QR, kredensial, atau perbaikan')
                ->color($needsAction > 0 ? 'danger' : 'gray'),
            Stat::make('Total koneksi', $total)
                ->description('Semua aplikasi')
                ->color('info'),
        ];
    }
}
