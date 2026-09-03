<?php

namespace App\Filament\Resources\RoutingPolicies;

use App\Filament\Resources\RoutingPolicies\Pages\CreateRoutingPolicy;
use App\Filament\Resources\RoutingPolicies\Pages\EditRoutingPolicy;
use App\Filament\Resources\RoutingPolicies\Pages\ListRoutingPolicies;
use App\Filament\Support\ConfigurationListLayout;
use App\Models\ClientApplication;
use App\Models\ProviderAccount;
use App\Models\RoutingPolicy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
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
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\Layout\Component;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
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
                                ->native(false)
                                ->live()
                                ->placeholder('Rute global')
                                ->helperText('Kosongkan untuk rute yang berlaku ke semua aplikasi.')
                                ->default(fn (): ?int => static::requestedClientApplicationId()),
                            TextInput::make('name')
                                ->label('Nama')
                                ->placeholder('Contoh: Notifikasi Shelf')
                                ->required()
                                ->maxLength(120),
                            Select::make('operation')
                                ->label('Jenis alur')
                                ->helperText('Kirim pesan dan cek nomor memakai rute terpisah.')
                                ->options([
                                    'message' => 'Kirim pesan',
                                    'number_check' => 'Cek nomor WhatsApp',
                                ])
                                ->default('message')
                                ->required()
                                ->native(false)
                                ->live(),
                            TextInput::make('key')
                                ->label('Kunci rute')
                                ->placeholder('default')
                                ->default('default')
                                ->helperText('Harus sama dengan route_key di aplikasi sumber. Gunakan default jika hanya ada satu rute.')
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
                                ->validationMessages([
                                    'unique' => 'Kunci rute ini sudah dipakai untuk aplikasi, jenis alur, dan tujuan yang sama.',
                                    'alpha_dash' => 'Gunakan huruf, angka, dan tanda hubung.',
                                ])
                                ->maxLength(80),
                            Select::make('purpose')
                                ->label('Tujuan')
                                ->options([
                                    'otp' => 'OTP',
                                    'transactional' => 'Transaksional',
                                    'notification' => 'Notifikasi',
                                ])
                                ->placeholder('Semua tujuan')
                                ->helperText('Kosongkan agar rute ini dipakai untuk semua tujuan.')
                                ->native(false)
                                ->live()
                                ->visible(fn (Get $get): bool => $get('operation') !== 'number_check')
                                ->dehydratedWhenHidden(false),
                            Toggle::make('is_default')
                                ->label('Rute default')
                                ->helperText('Dipakai jika aplikasi tidak mengirim route_key yang cocok.')
                                ->default(false),
                            Toggle::make('is_active')
                                ->label('Aturan aktif')
                                ->helperText('Nonaktifkan untuk berhenti memakai rute ini tanpa menghapusnya.')
                                ->default(true)
                                ->required(),
                        ])
                        ->columns(['md' => 2]),
                    Section::make('Urutan provider')
                        ->description('Seret provider ke urutan percobaan. Satu provider hanya boleh muncul sekali. Yang paling atas dicoba lebih dulu.')
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
                                        ->options(fn (): array => ProviderAccount::query()
                                            ->orderBy('name')
                                            ->get()
                                            ->mapWithKeys(function (ProviderAccount $account): array {
                                                $label = $account->name.' ('.strtoupper($account->driver).')';

                                                if (! $account->is_active) {
                                                    $label .= ' — nonaktif';
                                                }

                                                return [$account->id => $label];
                                            })
                                            ->all())
                                        ->searchable()
                                        ->preload()
                                        ->native(false)
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
                                ->itemLabel(function (array $state): ?string {
                                    $id = $state['provider_account_id'] ?? null;

                                    if (! filled($id)) {
                                        return 'Pilih provider';
                                    }

                                    return ProviderAccount::query()->find($id)?->name ?? 'Provider';
                                })
                                ->addActionLabel('Tambah cadangan'),
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
        $isCards = ConfigurationListLayout::isCards($table);

        return $table
            ->columns($isCards ? static::cardColumns() : static::tableColumns())
            ->contentGrid(ConfigurationListLayout::contentGrid($table))
            ->paginated([10, 25, 50])
            ->searchPlaceholder('Cari nama, aplikasi, atau kunci rute')
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
                static::deleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::deleteBulkAction(),
                ]),
            ])
            ->emptyStateHeading('Belum ada aturan rute')
            ->emptyStateDescription('Buat rute untuk aplikasi, kunci rute, dan urutan provider cadangan.')
            ->emptyStateIcon(Heroicon::OutlinedQueueList)
            ->emptyStateActions([
                Action::make('create')
                    ->label('Tambah aturan rute')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(static::getUrl('create')),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<int, Column>
     */
    public static function tableColumns(): array
    {
        return [
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
                ->label('Provider')
                ->counts('steps'),
            TextColumn::make('is_default')
                ->label('Default')
                ->badge()
                ->formatStateUsing(fn (mixed $state): string => $state ? 'Ya' : 'Tidak')
                ->color(fn (mixed $state): string => $state ? 'info' : 'gray'),
            TextColumn::make('is_active')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (mixed $state): string => $state ? 'Aktif' : 'Nonaktif')
                ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
        ];
    }

    /**
     * @return array<int, Component>
     */
    public static function cardColumns(): array
    {
        return [
            Stack::make([
                Split::make([
                    TextColumn::make('name')
                        ->weight(FontWeight::SemiBold)
                        ->size(TextSize::Large)
                        ->searchable()
                        ->sortable()
                        ->grow(),
                    TextColumn::make('is_active')
                        ->badge()
                        ->formatStateUsing(fn (mixed $state): string => $state ? 'Aktif' : 'Nonaktif')
                        ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
                ]),
                TextColumn::make('clientApplication.name')
                    ->placeholder('Rute global')
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->searchable(),
                Split::make([
                    TextColumn::make('operation')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'message' => 'Kirim pesan',
                            'number_check' => 'Cek nomor',
                            default => $state,
                        }),
                    TextColumn::make('key')
                        ->badge()
                        ->color('gray'),
                    TextColumn::make('purpose')
                        ->badge()
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'otp' => 'OTP',
                            'transactional' => 'Transaksional',
                            'notification' => 'Notifikasi',
                            default => $state ?? 'Semua tujuan',
                        })
                        ->placeholder('Semua tujuan'),
                ]),
                Split::make([
                    TextColumn::make('steps_count')
                        ->counts('steps')
                        ->icon(Heroicon::OutlinedServerStack)
                        ->formatStateUsing(fn (mixed $state): string => $state.' provider'),
                    TextColumn::make('is_default')
                        ->badge()
                        ->formatStateUsing(fn (mixed $state): string => $state ? 'Rute default' : 'Khusus')
                        ->color(fn (mixed $state): string => $state ? 'info' : 'gray'),
                ]),
            ])->space(3),
        ];
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Hapus')
            ->modalHeading('Hapus aturan rute?')
            ->modalDescription(function (RoutingPolicy $record): string {
                $parts = ['Rute ini tidak dipakai lagi saat pengiriman. Riwayat pesan tetap tersimpan.'];

                if ($record->is_default) {
                    $parts[] = 'Ini rute default; pastikan ada rute lain atau pengiriman bisa gagal.';
                }

                $parts[] = 'Kunci rute dapat dipakai ulang.';

                return implode(' ', $parts);
            })
            ->modalSubmitActionLabel('Hapus rute')
            ->successNotificationTitle('Aturan rute dihapus');
    }

    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->label('Hapus yang dipilih')
            ->modalHeading('Hapus aturan rute yang dipilih?')
            ->modalDescription('Rute yang dipilih tidak dipakai lagi saat pengiriman. Riwayat pesan tetap tersimpan.')
            ->modalSubmitActionLabel('Hapus')
            ->successNotificationTitle('Aturan rute dihapus');
    }

    public static function requestedClientApplicationId(): ?int
    {
        $id = request()->query('client_application_id');

        if (! is_numeric($id)) {
            return null;
        }

        $applicationId = (int) $id;

        return ClientApplication::query()->whereKey($applicationId)->value('id');
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
