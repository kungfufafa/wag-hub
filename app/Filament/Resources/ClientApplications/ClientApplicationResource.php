<?php

namespace App\Filament\Resources\ClientApplications;

use App\Filament\Resources\ClientApplications\Pages\CreateClientApplication;
use App\Filament\Resources\ClientApplications\Pages\EditClientApplication;
use App\Filament\Resources\ClientApplications\Pages\ListClientApplications;
use App\Filament\Resources\ClientApplications\RelationManagers\ApiCredentialsRelationManager;
use App\Models\ClientApplication;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class ClientApplicationResource extends Resource
{
    protected static ?string $model = ClientApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Application identity')
                ->description('Each source system receives isolated credentials and limits.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120),
                    TextInput::make('slug')
                        ->required()
                        ->alphaDash()
                        ->maxLength(80)
                        ->unique(ignoreRecord: true),
                    TextInput::make('rate_limit_per_minute')
                        ->label('Rate limit per minute')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(6000)
                        ->default(60),
                    Toggle::make('is_active')
                        ->label('Application active')
                        ->default(true)
                        ->required(),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->copyable()
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('rate_limit_per_minute')
                    ->label('Rate/min')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('api_credentials_count')
                    ->label('Credentials')
                    ->counts('apiCredentials'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            ApiCredentialsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListClientApplications::route('/'),
            'create' => CreateClientApplication::route('/create'),
            'edit' => EditClientApplication::route('/{record}/edit'),
        ];
    }
}
