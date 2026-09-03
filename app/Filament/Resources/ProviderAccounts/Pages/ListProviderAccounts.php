<?php

namespace App\Filament\Resources\ProviderAccounts\Pages;

use App\Filament\Resources\Concerns\HasTableCardToggle;
use App\Filament\Resources\ProviderAccounts\ProviderAccountResource;
use App\Filament\Resources\ProviderAccounts\Widgets\ProviderAccountStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListProviderAccounts extends ListRecords
{
    use HasTableCardToggle;

    protected static string $resource = ProviderAccountResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getSubheading(): ?string
    {
        return 'Pilih tampilan tabel atau kartu. Uji koneksi sebelum dipakai di rute.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...$this->viewModeActions(),
            CreateAction::make()
                ->label('Tambah akun provider')
                ->icon(Heroicon::OutlinedPlus),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ProviderAccountStats::class,
        ];
    }
}
