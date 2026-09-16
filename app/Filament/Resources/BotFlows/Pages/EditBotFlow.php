<?php

namespace App\Filament\Resources\BotFlows\Pages;

use App\Filament\Resources\BotFlows\BotFlowResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBotFlow extends EditRecord
{
    protected static string $resource = BotFlowResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
