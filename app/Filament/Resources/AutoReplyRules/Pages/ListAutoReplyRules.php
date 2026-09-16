<?php

namespace App\Filament\Resources\AutoReplyRules\Pages;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAutoReplyRules extends ListRecords
{
    protected static string $resource = AutoReplyRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
