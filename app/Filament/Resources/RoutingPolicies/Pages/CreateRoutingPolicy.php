<?php

namespace App\Filament\Resources\RoutingPolicies\Pages;

use App\Filament\Resources\RoutingPolicies\RoutingPolicyResource;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;

class CreateRoutingPolicy extends CreateRecord
{
    protected static string $resource = RoutingPolicyResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;
}
