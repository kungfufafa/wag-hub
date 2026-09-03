<?php

namespace App\Filament\Resources\RoutingPolicies\Pages;

use App\Filament\Resources\Concerns\HasTableCardToggle;
use App\Filament\Resources\RoutingPolicies\RoutingPolicyResource;
use App\Filament\Resources\RoutingPolicies\Widgets\RoutingPolicyStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListRoutingPolicies extends ListRecords
{
    use HasTableCardToggle;

    protected static string $resource = RoutingPolicyResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getSubheading(): ?string
    {
        return 'Pilih tampilan tabel atau kartu. Atur aplikasi, kunci rute, dan cadangan.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->viewModeActions(),
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
