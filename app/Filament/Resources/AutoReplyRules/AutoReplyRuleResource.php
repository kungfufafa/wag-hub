<?php

namespace App\Filament\Resources\AutoReplyRules;

use App\Filament\Resources\AutoReplyRules\Pages\CreateAutoReplyRule;
use App\Filament\Resources\AutoReplyRules\Pages\EditAutoReplyRule;
use App\Filament\Resources\AutoReplyRules\Pages\ListAutoReplyRules;
use App\Filament\Resources\AutoReplyRules\Schemas\AutoReplyRuleForm;
use App\Filament\Resources\AutoReplyRules\Tables\AutoReplyRulesTable;
use App\Filament\Support\PanelNavigation;
use App\Models\AutoReplyRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class AutoReplyRuleResource extends Resource
{
    protected static ?string $model = AutoReplyRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::BOTS;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Balasan Otomatis';

    protected static ?string $modelLabel = 'Balasan Otomatis';

    protected static ?string $pluralModelLabel = 'Balasan Otomatis';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return AutoReplyRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AutoReplyRulesTable::configure($table);
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
            'index' => ListAutoReplyRules::route('/'),
            'create' => CreateAutoReplyRule::route('/create'),
            'edit' => EditAutoReplyRule::route('/{record}/edit'),
        ];
    }
}
