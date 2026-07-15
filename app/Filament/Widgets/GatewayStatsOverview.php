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
            Stat::make('Queued', GatewayMessage::query()->where('status', 'queued')->count())
                ->description('Waiting for a worker')
                ->color('warning'),
            Stat::make(
                'Provider accepted',
                GatewayMessage::query()->where('status', 'provider_accepted')->count(),
            )
                ->description('Explicit provider acceptance')
                ->color('success'),
            Stat::make('Failed', GatewayMessage::query()->where('status', 'failed')->count())
                ->description('Safe failures requiring attention')
                ->color('danger'),
            Stat::make('Fallback rate', number_format($fallbackRate, 1).'%')
                ->description("{$fallbackMessageCount} messages used fallback")
                ->color($fallbackRate > 10 ? 'warning' : 'info'),
        ];
    }
}
