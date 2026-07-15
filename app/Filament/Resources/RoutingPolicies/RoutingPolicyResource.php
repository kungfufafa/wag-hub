<?php

namespace App\Filament\Resources\RoutingPolicies;

use App\Filament\Resources\RoutingPolicies\Pages\CreateRoutingPolicy;
use App\Filament\Resources\RoutingPolicies\Pages\EditRoutingPolicy;
use App\Filament\Resources\RoutingPolicies\Pages\ListRoutingPolicies;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class RoutingPolicyResource extends Resource
{
    protected static ?string $model = RoutingPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Aturan Pengiriman';

    protected static ?string $modelLabel = 'Aturan Pengiriman';

    protected static ?string $pluralModelLabel = 'Aturan Pengiriman';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['xl' => 3])
                ->schema([
                    Group::make([
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
                                    ->scopedUnique(
                                        model: RoutingPolicy::class,
                                        ignoreRecord: true,
                                        modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                            ->where('client_application_id', $get('client_application_id'))
                                            ->where('purpose', $get('purpose')),
                                    )
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
                            ->columns(['md' => 2]),
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
                    ])->columnSpan(['xl' => 2]),
                    Group::make([
                        Section::make('Urutan pengiriman')
                            ->description('Hub mencoba provider dari atas ke bawah.')
                            ->schema([
                                Placeholder::make('route_scope')
                                    ->label('1. Tentukan aplikasi')
                                    ->content('Pilih aplikasi agar route ini tidak memengaruhi aplikasi lain.'),
                                Placeholder::make('route_match')
                                    ->label('2. Tentukan route key')
                                    ->content('Gunakan default bila aplikasi hanya memiliki satu jalur pengiriman.'),
                                Placeholder::make('route_fallback')
                                    ->label('3. Susun fallback')
                                    ->content('Taruh provider utama di urutan pertama, lalu provider cadangan setelahnya.'),
                            ]),
                    ])->columnSpan(['xl' => 1]),
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
