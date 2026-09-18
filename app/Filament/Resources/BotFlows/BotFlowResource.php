<?php

namespace App\Filament\Resources\BotFlows;

use App\Filament\Resources\BotFlows\Pages\CreateBotFlow;
use App\Filament\Resources\BotFlows\Pages\EditBotFlow;
use App\Filament\Resources\BotFlows\Pages\ListBotFlows;
use App\Filament\Resources\BotFlows\Schemas\BotFlowForm;
use App\Filament\Resources\BotFlows\Tables\BotFlowsTable;
use App\Filament\Support\PanelNavigation;
use App\Models\BotFlow;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class BotFlowResource extends Resource
{
    protected static ?string $model = BotFlow::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::AUTOMATION;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Menu Bot';

    protected static ?string $modelLabel = 'Menu Bot';

    protected static ?string $pluralModelLabel = 'Menu Bot';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return BotFlowForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BotFlowsTable::configure($table);
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
            'index' => ListBotFlows::route('/'),
            'create' => CreateBotFlow::route('/create'),
            'edit' => EditBotFlow::route('/{record}/edit'),
        ];
    }
}
