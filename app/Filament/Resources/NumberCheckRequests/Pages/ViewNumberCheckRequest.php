<?php

namespace App\Filament\Resources\NumberCheckRequests\Pages;

use App\Filament\Resources\NumberCheckRequests\NumberCheckRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewNumberCheckRequest extends ViewRecord
{
    protected static string $resource = NumberCheckRequestResource::class;

    public function getTitle(): string
    {
        return 'Detail pengecekan nomor';
    }

    public function getHeading(): string
    {
        return 'Detail pengecekan nomor';
    }
}
