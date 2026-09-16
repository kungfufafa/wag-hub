<?php

namespace App\Filament\Resources\BotFlows\Pages;

use App\Filament\Resources\BotFlows\BotFlowResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBotFlows extends ListRecords
{
    protected static string $resource = BotFlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
