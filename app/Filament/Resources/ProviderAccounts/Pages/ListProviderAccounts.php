<?php

namespace App\Filament\Resources\ProviderAccounts\Pages;

use App\Filament\Resources\ProviderAccounts\ProviderAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProviderAccounts extends ListRecords
{
    protected static string $resource = ProviderAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
