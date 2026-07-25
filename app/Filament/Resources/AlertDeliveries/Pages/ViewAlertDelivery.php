<?php

namespace App\Filament\Resources\AlertDeliveries\Pages;

use App\Filament\Resources\AlertDeliveries\AlertDeliveryResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAlertDelivery extends ViewRecord
{
    protected static string $resource = AlertDeliveryResource::class;

    public function getTitle(): string
    {
        return 'Detail pengiriman alert';
    }

    public function getHeading(): string
    {
        return 'Detail pengiriman alert';
    }
}
