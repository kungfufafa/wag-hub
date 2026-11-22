<?php

namespace App\Filament\Resources\ProviderAccounts\Pages;

use App\Filament\Resources\ProviderAccounts\ProviderAccountResource;
use App\Filament\Resources\ProviderAccounts\Widgets\ProviderAccountStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ListProviderAccounts extends ListRecords
{
    protected static string $resource = ProviderAccountResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    protected function getHeaderActions(): array
    {
        return [
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
