<?php

namespace App\Filament\Resources\GatewayMessages\Pages;

use App\Filament\Resources\GatewayMessages\GatewayMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListGatewayMessages extends ListRecords
{
    protected static string $resource = GatewayMessageResource::class;
}
