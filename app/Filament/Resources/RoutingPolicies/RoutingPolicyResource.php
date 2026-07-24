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
use Illuminate\Support\Facades\DB;
use UnitEnum;

class RoutingPolicyResource extends Resource
{
    protected static ?string $model = RoutingPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Konfigurasi';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Aturan Rute';

    protected static ?string $modelLabel = 'Aturan Rute';

    protected static ?string $pluralModelLabel = 'Aturan Rute';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'lg' => 3,
            ])
            ->components([
                Group::make([
                    Section::make('Aturan rute')
                        ->description('Aturan khusus aplikasi menimpa rute global.')
                        ->schema([
                            Select::make('client_application_id')
                                ->label('Aplikasi')
                                ->relationship('clientApplication', 'name')
                                ->searchable()
                                ->preload()
                                ->placeholder('Rute global'),
                            TextInput::make('name')
                                ->label('Nama')
                                ->required()
                                ->maxLength(120),
                            Select::make('operation')
                                ->label('Jenis alur')
                                ->options([
                                    'message' => 'Kirim pesan',
                                    'number_check' => 'Cek nomor WhatsApp',
                                ])
                                ->default('message')
                                ->required()
                                ->live(),
                            TextInput::make('key')
                                ->label('Kunci rute')
                                ->required()
                                ->alphaDash()
                                ->scopedUnique(
                                    model: RoutingPolicy::class,
                                    ignoreRecord: true,
                                    modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query
                                        ->where('client_application_id', $get('client_application_id'))
                                        ->where('operation', $get('operation'))
                                        ->where(
                                            'purpose',
                                            $get('operation') === 'number_check' ? null : $get('purpose'),
                                        ),
                                )
                                ->maxLength(80),
                            Select::make('purpose')
                                ->label('Tujuan')
                                ->options([
                                    'otp' => 'OTP',
                                    'transactional' => 'Transaksional',
                                    'notification' => 'Notifikasi',
                                ])
                                ->placeholder('Semua tujuan')
                                ->visible(fn (Get $get): bool => $get('operation') !== 'number_check')
                                ->dehydratedWhenHidden(false),
                            Toggle::make('is_default')
                                ->label('Rute default')
                                ->default(false),
                            Toggle::make('is_active')
                                ->label('Aturan aktif')
                                ->default(true)
                                ->required(),
                        ])
                        ->columns(['md' => 2]),
                    Section::make('Urutan provider')
                        ->description('Seret provider ke urutan percobaan. Satu provider hanya boleh muncul sekali.')
                        ->schema([
                            Repeater::make('steps')
                                ->relationship()
                                ->orderColumn('position')
                                ->saveRelationshipsUsing(function (Repeater $component): void {
                                    // Filament rewrites order one row at a time. Free the unique
                                    // (routing_policy_id, position) slots first, then reload models
                                    // so Eloquent dirty-checks see the temporary positions.
                                    $component->getRelationship()->getQuery()->update([
                                        'position' => DB::raw('position + 100000'),
                                    ]);
                                    $component->clearCachedExistingRecords();
                                    $component->saveToRelationship();
                                })
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
                                        ->label('Langkah aktif')
                                        ->default(true)
                                        ->required(),
                                ])
                                ->columns(2)
                                ->minItems(1)
                                ->required()
                                ->reorderable()
                                ->addActionLabel('Tambah provider'),
                        ]),
                ])->columnSpan([
                    'default' => 'full',
                    'lg' => 2,
                ]),
                Section::make('Cara kerja rute')
                    ->description('Jenis alur menentukan endpoint yang boleh memakai rute ini.')
                    ->schema([
                        Placeholder::make('route_scope')
                            ->label('1. Tentukan aplikasi')
                            ->content('Pilih aplikasi agar rute ini tidak memengaruhi aplikasi lain.'),
                        Placeholder::make('route_match')
                            ->label('2. Tentukan kunci rute')
                            ->content('Gunakan default bila aplikasi hanya memiliki satu rute untuk jenis alur ini.'),
                        Placeholder::make('route_fallback')
                            ->label('3. Susun cadangan')
                            ->content('Provider dicoba dari atas ke bawah sampai mendapat hasil definitif.'),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 1,
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('clientApplication.name')
                    ->label('Aplikasi')
                    ->placeholder('Global')
                    ->searchable(),
                TextColumn::make('operation')
                    ->label('Jenis alur')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'message' => 'Kirim pesan',
                        'number_check' => 'Cek nomor',
                        default => $state,
                    }),
                TextColumn::make('key')
                    ->label('Kunci rute')
                    ->badge(),
                TextColumn::make('purpose')
                    ->label('Tujuan')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'otp' => 'OTP',
                        'transactional' => 'Transaksional',
                        'notification' => 'Notifikasi',
                        default => $state ?? 'Semua',
                    })
                    ->placeholder('Semua'),
                TextColumn::make('steps_count')
                    ->label('Langkah')
                    ->counts('steps'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('client_application_id')
                    ->label('Aplikasi')
                    ->relationship('clientApplication', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('purpose')
                    ->label('Tujuan')
                    ->options([
                        'otp' => 'OTP',
                        'transactional' => 'Transaksional',
                        'notification' => 'Notifikasi',
                    ]),
                SelectFilter::make('operation')
                    ->label('Jenis alur')
                    ->options([
                        'message' => 'Kirim pesan',
                        'number_check' => 'Cek nomor WhatsApp',
                    ]),
                TernaryFilter::make('is_active')->label('Aktif'),
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
