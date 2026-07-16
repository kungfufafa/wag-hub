<?php

namespace App\Filament\Resources\ProviderAccounts;

use App\Filament\Resources\ProviderAccounts\Pages\CreateProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\EditProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\ListProviderAccounts;
use App\Models\ProviderAccount;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
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
use UnitEnum;

class ProviderAccountResource extends Resource
{
    protected static ?string $model = ProviderAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Konfigurasi';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Akun Provider';

    protected static ?string $modelLabel = 'Akun Provider';

    protected static ?string $pluralModelLabel = 'Akun Provider';

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
                    Section::make('Akun provider')
                        ->description('Secret hanya bisa ditulis ulang dan disimpan terenkripsi.')
                        ->schema([
                            TextInput::make('name')
                                ->label('Nama')
                                ->required()
                                ->maxLength(120),
                            TextInput::make('slug')
                                ->label('ID provider')
                                ->required()
                                ->alphaDash()
                                ->maxLength(80)
                                ->unique(ignoreRecord: true),
                            Select::make('driver')
                                ->label('Driver')
                                ->options([
                                    'waha' => 'WAHA',
                                    'fonnte' => 'Fonnte',
                                    'gowa' => 'GOWA',
                                    'waba' => 'WABA (Meta Cloud API)',
                                ])
                                ->required()
                                ->live(),
                            TextInput::make('timeout_seconds')
                                ->label('Timeout (detik)')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(60)
                                ->default(15),
                            Toggle::make('is_active')
                                ->label('Provider aktif')
                                ->default(true)
                                ->required(),
                        ])
                        ->columns(['md' => 2]),
                    Section::make('Koneksi WAHA')
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
                                ->helperText('Kosongkan saat mengubah bila ingin mempertahankan kunci yang ada.')
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->dehydratedWhenHidden(false),
                        ])
                        ->visible(fn (Get $get): bool => $get('driver') === 'waha')
                        ->columns(['md' => 2]),
                    Section::make('Koneksi Fonnte')
                        ->schema([
                            TextInput::make('configuration.endpoint')
                                ->label('Endpoint')
                                ->url()
                                ->required(fn (Get $get): bool => $get('driver') === 'fonnte')
                                ->default('https://api.fonnte.com/send')
                                ->dehydratedWhenHidden(false),
                            TextInput::make('configuration.validate_endpoint')
                                ->label('Endpoint validasi nomor')
                                ->url()
                                ->required(fn (Get $get): bool => $get('driver') === 'fonnte')
                                ->default('https://api.fonnte.com/validate')
                                ->dehydratedWhenHidden(false),
                            TextInput::make('configuration.token')
                                ->label('Token')
                                ->password()
                                ->revealable()
                                ->helperText('Kosongkan saat mengubah bila ingin mempertahankan token yang ada.')
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->dehydratedWhenHidden(false),
                        ])
                        ->visible(fn (Get $get): bool => $get('driver') === 'fonnte')
                        ->columns(['md' => 2]),
                    Section::make('Koneksi GOWA')
                        ->schema([
                            TextInput::make('configuration.base_url')
                                ->label('Base URL')
                                ->url()
                                ->required(fn (Get $get): bool => $get('driver') === 'gowa')
                                ->dehydratedWhenHidden(false),
                            TextInput::make('configuration.username')
                                ->label('Username Basic Auth')
                                ->required(fn (Get $get): bool => $get('driver') === 'gowa')
                                ->maxLength(120)
                                ->dehydratedWhenHidden(false),
                            TextInput::make('configuration.password')
                                ->label('Password Basic Auth')
                                ->password()
                                ->revealable()
                                ->helperText('Kosongkan saat mengubah bila ingin mempertahankan password yang ada.')
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->dehydratedWhenHidden(false),
                            TextInput::make('configuration.device_id')
                                ->label('Device ID')
                                ->helperText('Opsional bila server GOWA hanya memiliki satu device.')
                                ->maxLength(255)
                                ->dehydratedWhenHidden(false),
                        ])
                        ->visible(fn (Get $get): bool => $get('driver') === 'gowa')
                        ->columns(['md' => 2]),
                    Section::make('Koneksi WABA')
                        ->description('Meta Cloud API resmi. Lookup registrasi nomor tidak tersedia; pengecekan akan berstatus unsupported.')
                        ->schema([
                            TextInput::make('configuration.base_url')
                                ->label('Graph API Base URL')
                                ->url()
                                ->default('https://graph.facebook.com')
                                ->required(fn (Get $get): bool => $get('driver') === 'waba')
                                ->dehydratedWhenHidden(false),
                            TextInput::make('configuration.api_version')
                                ->label('Graph API Version')
                                ->default('v25.0')
                                ->regex('/^v\d+\.\d+$/')
                                ->required(fn (Get $get): bool => $get('driver') === 'waba')
                                ->dehydratedWhenHidden(false),
                            TextInput::make('configuration.phone_number_id')
                                ->label('Phone Number ID')
                                ->regex('/^\d+$/')
                                ->required(fn (Get $get): bool => $get('driver') === 'waba')
                                ->dehydratedWhenHidden(false),
                            TextInput::make('configuration.access_token')
                                ->label('System User Access Token')
                                ->password()
                                ->revealable()
                                ->helperText('Gunakan token system user; kosongkan saat mengubah untuk mempertahankan token.')
                                ->required(fn (string $operation): bool => $operation === 'create')
                                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                                ->dehydrated(fn (?string $state): bool => filled($state))
                                ->dehydratedWhenHidden(false),
                        ])
                        ->visible(fn (Get $get): bool => $get('driver') === 'waba')
                        ->columns(['md' => 2]),
                ])->columnSpan([
                    'default' => 'full',
                    'lg' => 2,
                ]),
                Section::make('Sebelum menyimpan')
                    ->description('Provider hanya dipakai oleh Hub, bukan aplikasi sumber.')
                    ->schema([
                        Placeholder::make('provider_connection')
                            ->label('1. Lengkapi koneksi')
                            ->content('Isi koneksi sesuai driver: WAHA, Fonnte, GOWA, atau Meta WABA.'),
                        Placeholder::make('provider_secret')
                            ->label('2. Simpan secret')
                            ->content('API key atau token disimpan terenkripsi dan tidak dapat dilihat kembali.'),
                        Placeholder::make('provider_activation')
                            ->label('3. Aktifkan lalu uji')
                            ->content('Provider aktif akan dipakai oleh rute yang menempatkannya pada urutan cadangan.'),
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
                TextColumn::make('driver')
                    ->label('Driver')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                TextColumn::make('health_status')
                    ->label('Kesehatan')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'unknown' => 'Tidak diketahui',
                        'healthy' => 'Sehat',
                        'degraded' => 'Menurun',
                        'unavailable' => 'Tidak tersedia',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'healthy' => 'success',
                        'degraded' => 'warning',
                        'unavailable' => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('consecutive_failures')
                    ->label('Kegagalan')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('circuit_open_until')
                    ->label('Circuit terbuka hingga')
                    ->dateTime()
                    ->placeholder('Tertutup'),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('driver')
                    ->label('Driver')
                    ->options([
                        'waha' => 'WAHA',
                        'fonnte' => 'Fonnte',
                        'gowa' => 'GOWA',
                        'waba' => 'WABA (Meta Cloud API)',
                    ]),
                SelectFilter::make('health_status')
                    ->label('Kesehatan')
                    ->options([
                        'unknown' => 'Tidak diketahui',
                        'healthy' => 'Sehat',
                        'degraded' => 'Menurun',
                        'unavailable' => 'Tidak tersedia',
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
            'index' => ListProviderAccounts::route('/'),
            'create' => CreateProviderAccount::route('/create'),
            'edit' => EditProviderAccount::route('/{record}/edit'),
        ];
    }
}
