<?php

namespace App\Filament\Resources\ClientApplications;

use App\Filament\Resources\ClientApplications\Pages\CreateClientApplication;
use App\Filament\Resources\ClientApplications\Pages\EditClientApplication;
use App\Filament\Resources\ClientApplications\Pages\ListClientApplications;
use App\Filament\Resources\ClientApplications\RelationManagers\ApiCredentialsRelationManager;
use App\Models\ClientApplication;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
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

    protected static string|UnitEnum|null $navigationGroup = 'Konfigurasi';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Aplikasi Klien';

    protected static ?string $modelLabel = 'Aplikasi Klien';

    protected static ?string $pluralModelLabel = 'Aplikasi Klien';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'lg' => 3,
            ])
            ->components([
                Section::make('Identitas aplikasi')
                    ->description('Satu aplikasi sumber memakai kredensial dan batas pengirimannya sendiri.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama aplikasi')
                            ->required()
                            ->maxLength(120),
                        TextInput::make('slug')
                            ->label('ID aplikasi')
                            ->helperText('Gunakan huruf kecil, angka, dan tanda hubung. Tidak dapat dipakai ulang.')
                            ->required()
                            ->alphaDash()
                            ->maxLength(80)
                            ->unique(ignoreRecord: true),
                        TextInput::make('rate_limit_per_minute')
                            ->label('Batas kirim per menit')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(6000)
                            ->default(60),
                        Toggle::make('is_active')
                            ->label('Aplikasi aktif')
                            ->default(true)
                            ->required(),
                    ])
                    ->columns(['md' => 2])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 2,
                    ]),
                Section::make('Langkah berikutnya')
                    ->description('Selesaikan setup aplikasi dalam urutan ini.')
                    ->schema([
                        Placeholder::make('create_credential')
                            ->label('1. Buat kredensial')
                            ->content('Setelah disimpan, buka tab Kredensial API untuk membuat token aplikasi.'),
                        Placeholder::make('choose_route')
                            ->label('2. Pilih rute pengiriman')
                            ->content('Atur rute default aplikasi ini dari menu Aturan Pengiriman.'),
                        Placeholder::make('activate_application')
                            ->label('3. Aktifkan aplikasi')
                            ->content('Nonaktifkan aplikasi untuk menghentikan pengiriman tanpa menghapus riwayat.'),
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
                TextColumn::make('slug')
                    ->label('ID aplikasi')
                    ->copyable()
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean(),
                TextColumn::make('rate_limit_per_minute')
                    ->label('Batas/menit')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('api_credentials_count')
                    ->label('Kredensial')
                    ->counts('apiCredentials'),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Aktif'),
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
