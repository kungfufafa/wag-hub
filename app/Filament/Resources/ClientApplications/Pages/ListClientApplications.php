<?php

namespace App\Filament\Resources\ClientApplications\Pages;

use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use App\Filament\Resources\ClientApplications\Widgets\ClientApplicationStats;
use App\Filament\Resources\Concerns\HasTableCardToggle;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListClientApplications extends ListRecords
{
    use HasTableCardToggle;

    protected static string $resource = ClientApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getSubheading(): ?string
    {
        return 'Pilih tampilan tabel atau kartu. Kelola aplikasi sumber, token, dan rute.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->viewModeActions(),
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
