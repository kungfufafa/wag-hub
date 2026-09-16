<?php

namespace App\Filament\Resources\KnowledgeBaseEntries\Pages;

use App\Filament\Resources\KnowledgeBaseEntries\KnowledgeBaseEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeBaseEntries extends ListRecords
{
    protected static string $resource = KnowledgeBaseEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
