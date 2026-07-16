<?php

namespace App\Filament\Widgets;

use App\Models\GatewayMessage;
use App\Models\MessageEvent;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class GatewayStatsOverview extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '15s';

    protected function getStats(): array
    {
        $messageCount = GatewayMessage::query()->count();
        $fallbackMessageCount = MessageEvent::query()
            ->where('type', 'fallback_started')
            ->distinct()
            ->count('gateway_message_id');
        $fallbackRate = $messageCount === 0
            ? 0
            : round(($fallbackMessageCount / $messageCount) * 100, 1);

        return [
            Stat::make('Antrian', GatewayMessage::query()->where('status', 'queued')->count())
                ->description('Menunggu worker')
                ->color('warning'),
            Stat::make(
                'Diterima provider',
                GatewayMessage::query()->where('status', 'provider_accepted')->count(),
            )
                ->description('Penerimaan eksplisit dari provider')
                ->color('success'),
            Stat::make('Gagal', GatewayMessage::query()->where('status', 'failed')->count())
                ->description('Kegagalan aman yang perlu perhatian')
                ->color('danger'),
            Stat::make('Rasio cadangan', number_format($fallbackRate, 1).'%')
                ->description("{$fallbackMessageCount} pesan memakai jalur cadangan")
                ->color($fallbackRate > 10 ? 'warning' : 'info'),
        ];
    }
}
