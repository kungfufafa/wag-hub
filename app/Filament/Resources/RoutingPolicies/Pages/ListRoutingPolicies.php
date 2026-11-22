<?php

namespace App\Filament\Resources\RoutingPolicies\Pages;

use App\Filament\Resources\RoutingPolicies\RoutingPolicyResource;
use App\Filament\Resources\RoutingPolicies\Widgets\RoutingPolicyStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListRoutingPolicies extends ListRecords
{
    protected static string $resource = RoutingPolicyResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah aturan rute')
                ->icon(Heroicon::OutlinedPlus),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            RoutingPolicyStats::class,
        ];
    }
}
