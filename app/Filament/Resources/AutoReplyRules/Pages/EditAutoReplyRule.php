<?php

namespace App\Filament\Resources\AutoReplyRules\Pages;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAutoReplyRule extends EditRecord
{
    protected static string $resource = AutoReplyRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
