<?php

namespace App\Filament\Resources\ClientApplications\Pages;

use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use App\Filament\Resources\ClientApplications\Widgets\ClientApplicationStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListClientApplications extends ListRecords
{
    protected static string $resource = ClientApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Tambah aplikasi klien')
                ->icon(Heroicon::OutlinedPlus),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ClientApplicationStats::class,
        ];
    }
}
