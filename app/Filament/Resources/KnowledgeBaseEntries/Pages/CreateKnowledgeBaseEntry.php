<?php

namespace App\Filament\Resources\KnowledgeBaseEntries\Pages;

use App\Filament\Resources\KnowledgeBaseEntries\KnowledgeBaseEntryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeBaseEntry extends CreateRecord
{
    protected static string $resource = KnowledgeBaseEntryResource::class;
}
