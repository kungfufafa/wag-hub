<?php

namespace App\Filament\Resources\KnowledgeBaseEntries;

use App\Filament\Resources\KnowledgeBaseEntries\Pages\CreateKnowledgeBaseEntry;
use App\Filament\Resources\KnowledgeBaseEntries\Pages\EditKnowledgeBaseEntry;
use App\Filament\Resources\KnowledgeBaseEntries\Pages\ListKnowledgeBaseEntries;
use App\Filament\Resources\KnowledgeBaseEntries\Schemas\KnowledgeBaseEntryForm;
use App\Filament\Resources\KnowledgeBaseEntries\Tables\KnowledgeBaseEntriesTable;
use App\Filament\Support\PanelNavigation;
use App\Models\KnowledgeBaseEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class KnowledgeBaseEntryResource extends Resource
{
    protected static ?string $model = KnowledgeBaseEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::AUTOMATION;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Knowledge Base (AI)';

    protected static ?string $modelLabel = 'Knowledge Base';

    protected static ?string $pluralModelLabel = 'Knowledge Base';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return KnowledgeBaseEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KnowledgeBaseEntriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKnowledgeBaseEntries::route('/'),
            'create' => CreateKnowledgeBaseEntry::route('/create'),
            'edit' => EditKnowledgeBaseEntry::route('/{record}/edit'),
        ];
    }
}
