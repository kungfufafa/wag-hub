<?php

namespace App\Filament\Resources\ClientApplications\Pages;

use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateClientApplication extends CreateRecord
{
    protected static string $resource = ClientApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;
}
