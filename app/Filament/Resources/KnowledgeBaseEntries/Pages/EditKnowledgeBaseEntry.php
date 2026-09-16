<?php

namespace App\Filament\Resources\KnowledgeBaseEntries\Pages;

use App\Filament\Resources\KnowledgeBaseEntries\KnowledgeBaseEntryResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeBaseEntry extends EditRecord
{
    protected static string $resource = KnowledgeBaseEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
