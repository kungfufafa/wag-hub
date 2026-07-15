<?php

namespace App\Filament\Resources\RoutingPolicies;

use App\Filament\Resources\RoutingPolicies\Pages\CreateRoutingPolicy;
use App\Filament\Resources\RoutingPolicies\Pages\EditRoutingPolicy;
use App\Filament\Resources\RoutingPolicies\Pages\ListRoutingPolicies;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class RoutingPolicyResource extends Resource
{
    protected static ?string $model = RoutingPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Routing policy')
                ->description('Application-specific policies override global routes.')
                ->schema([
                    Select::make('client_application_id')
                        ->label('Application')
                        ->relationship('clientApplication', 'name')
                        ->searchable()
                        ->preload()
                        ->placeholder('Global policy'),
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120),
                    TextInput::make('key')
                        ->label('Route key')
                        ->required()
                        ->alphaDash()
                        ->maxLength(80),
                    Select::make('purpose')
                        ->options([
                            'otp' => 'OTP',
                            'transactional' => 'Transactional',
                            'notification' => 'Notification',
                        ])
                        ->placeholder('Any purpose'),
                    Toggle::make('is_default')
                        ->label('Default route')
                        ->default(false),
                    Toggle::make('is_active')
                        ->label('Policy active')
                        ->default(true)
                        ->required(),
                ])
                ->columns(2),
            Section::make('Provider order')
                ->description('Drag providers into fallback order. A provider can appear only once.')
                ->schema([
                    Repeater::make('steps')
                        ->relationship()
                        ->orderColumn('position')
                        ->schema([
                            Select::make('provider_account_id')
                                ->label('Provider')
                                ->options(fn () => ProviderAccount::query()
                                    ->where('is_active', true)
                                    ->orderBy('name')
                                    ->pluck('name', 'id'))
                                ->searchable()
                                ->preload()
                                ->required()
                                ->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                            Toggle::make('is_active')
                                ->label('Step active')
                                ->default(true)
                                ->required(),
                        ])
                        ->columns(2)
                        ->minItems(1)
                        ->required()
                        ->reorderable()
                        ->addActionLabel('Add provider'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('clientApplication.name')
                    ->label('Application')
                    ->placeholder('Global')
                    ->searchable(),
                TextColumn::make('key')
                    ->label('Route key')
                    ->badge(),
                TextColumn::make('purpose')
                    ->badge()
                    ->placeholder('Any'),
                TextColumn::make('steps_count')
                    ->label('Steps')
                    ->counts('steps'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('client_application_id')
                    ->label('Application')
                    ->relationship('clientApplication', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('purpose')->options([
                    'otp' => 'OTP',
                    'transactional' => 'Transactional',
                    'notification' => 'Notification',
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
            'index' => ListRoutingPolicies::route('/'),
            'create' => CreateRoutingPolicy::route('/create'),
            'edit' => EditRoutingPolicy::route('/{record}/edit'),
        ];
    }
}
