<?php

namespace App\Filament\Resources\ProviderAccounts;

use App\Filament\Resources\ProviderAccounts\Pages\CreateProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\EditProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\ListProviderAccounts;
use App\Models\ProviderAccount;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProviderAccountResource extends Resource
{
    protected static ?string $model = ProviderAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Provider account')
                ->description('Secrets are write-only and remain encrypted at rest.')
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120),
                    TextInput::make('slug')
                        ->required()
                        ->alphaDash()
                        ->maxLength(80)
                        ->unique(ignoreRecord: true),
                    Select::make('driver')
                        ->options([
                            'waha' => 'WAHA',
                            'fonnte' => 'Fonnte',
                        ])
                        ->required()
                        ->live(),
                    TextInput::make('timeout_seconds')
                        ->label('Timeout (seconds)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->maxValue(60)
                        ->default(15),
                    Toggle::make('is_active')
                        ->label('Provider active')
                        ->default(true)
                        ->required(),
                ])
                ->columns(2),
            Section::make('WAHA connection')
                ->schema([
                    TextInput::make('configuration.base_url')
                        ->label('Base URL')
                        ->url()
                        ->required(fn (Get $get): bool => $get('driver') === 'waha')
                        ->dehydratedWhenHidden(false),
                    TextInput::make('configuration.session')
                        ->label('Session')
                        ->required(fn (Get $get): bool => $get('driver') === 'waha')
                        ->maxLength(120)
                        ->dehydratedWhenHidden(false),
                    TextInput::make('configuration.api_key')
                        ->label('API key')
                        ->password()
                        ->revealable()
                        ->helperText('Leave blank while editing to keep the existing key.')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->dehydratedWhenHidden(false),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === 'waha')
                ->columns(2),
            Section::make('Fonnte connection')
                ->schema([
                    TextInput::make('configuration.endpoint')
                        ->label('Endpoint')
                        ->url()
                        ->required(fn (Get $get): bool => $get('driver') === 'fonnte')
                        ->default('https://api.fonnte.com/send')
                        ->dehydratedWhenHidden(false),
                    TextInput::make('configuration.token')
                        ->label('Token')
                        ->password()
                        ->revealable()
                        ->helperText('Leave blank while editing to keep the existing token.')
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->dehydratedWhenHidden(false),
                ])
                ->visible(fn (Get $get): bool => $get('driver') === 'fonnte')
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
                TextColumn::make('driver')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('health_status')
                    ->label('Health')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'unavailable' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('consecutive_failures')
                    ->label('Failures')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('circuit_open_until')
                    ->label('Circuit open until')
                    ->dateTime()
                    ->placeholder('Closed'),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('driver')->options([
                    'waha' => 'WAHA',
                    'fonnte' => 'Fonnte',
                ]),
                SelectFilter::make('health_status')->options([
                    'unknown' => 'Unknown',
                    'healthy' => 'Healthy',
                    'degraded' => 'Degraded',
                    'unavailable' => 'Unavailable',
                ]),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviderAccounts::route('/'),
            'create' => CreateProviderAccount::route('/create'),
            'edit' => EditProviderAccount::route('/{record}/edit'),
        ];
    }
}
