<?php

namespace App\Filament\Resources\ClientApplications\Pages;

use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClientApplications extends ListRecords
{
    protected static string $resource = ClientApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
