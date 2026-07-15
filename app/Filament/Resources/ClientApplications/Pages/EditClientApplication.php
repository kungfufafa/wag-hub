<?php

namespace App\Filament\Resources\ClientApplications\Pages;

use App\Filament\Resources\ClientApplications\ClientApplicationResource;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;

class EditClientApplication extends EditRecord
{
    protected static string $resource = ClientApplicationResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;
}
