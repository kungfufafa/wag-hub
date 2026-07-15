<?php

namespace App\Filament\Resources\ProviderAccounts\Pages;

use App\Filament\Resources\ProviderAccounts\ProviderAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProviderAccount extends CreateRecord
{
    protected static string $resource = ProviderAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['health_status'] = 'unknown';
        $data['consecutive_failures'] = 0;

        return $data;
    }
}
