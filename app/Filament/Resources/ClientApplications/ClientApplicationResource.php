<?php

namespace App\Filament\Resources\ClientApplications;

use App\Filament\Resources\ClientApplications\Pages\CreateClientApplication;
use App\Filament\Resources\ClientApplications\Pages\EditClientApplication;
use App\Filament\Resources\ClientApplications\Pages\ListClientApplications;
use App\Filament\Resources\ClientApplications\RelationManagers\ApiCredentialsRelationManager;
use App\Filament\Resources\RoutingPolicies\RoutingPolicyResource;
use App\Filament\Support\ConfigurationListLayout;
use App\Filament\Support\PanelNavigation;
use App\Filament\Support\SyncsSlugFromName;
use App\Models\ClientApplication;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Component;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class ClientApplicationResource extends Resource
{
    protected static ?string $model = ClientApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::APPS;

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
                            ->placeholder('Contoh: Web Shelf')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->afterStateUpdated(SyncsSlugFromName::afterStateUpdated()),
                        TextInput::make('slug')
                            ->label('ID aplikasi')
                            ->placeholder('web-shelf')
                            ->helperText('Diisi otomatis dari nama. Boleh diubah sebelum disimpan; tidak dapat dipakai ulang selama aplikasi masih ada.')
                            ->alphaDash()
                            ->maxLength(80)
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->withoutTrashed(),
                            )
                            ->validationMessages([
                                'unique' => 'ID aplikasi ini sudah dipakai.',
                                'alpha_dash' => 'Gunakan huruf kecil, angka, dan tanda hubung.',
                            ]),
                        TextInput::make('website_url')
                            ->label('URL aplikasi')
                            ->placeholder('https://shelf.example.com')
                            ->helperText('Opsional. Alamat aplikasi sumber, untuk memudahkan admin mengenali aplikasi.')
                            ->url()
                            ->rule('starts_with:http://,https://')
                            ->maxLength(2048)
                            ->nullable()
                            ->validationMessages([
                                'url' => 'Masukkan URL lengkap, misalnya https://shelf.example.com.',
                                'starts_with' => 'URL harus diawali http:// atau https://.',
                            ]),
                        FileUpload::make('logo_path')
                            ->label('Logo aplikasi')
                            ->helperText('Opsional. PNG, JPG, atau WebP, maksimal 1 MB.')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->maxSize(1024)
                            ->disk(ClientApplication::LOGO_DISK)
                            ->directory(ClientApplication::LOGO_DIRECTORY)
                            ->visibility('public')
                            ->imagePreviewHeight('96')
                            ->nullable(),
                        TextInput::make('rate_limit_per_minute')
                            ->label('Batas kirim per menit')
                            ->helperText('Batas request API per menit untuk aplikasi ini. Nilai umum 30–120.')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(6000)
                            ->default(60),
                        Toggle::make('is_active')
                            ->label('Aplikasi aktif')
                            ->helperText('Nonaktifkan untuk menghentikan pengiriman tanpa menghapus riwayat.')
                            ->default(true)
                            ->required(),
                    ])
                    ->columns(['md' => 2])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 2,
                    ]),
                Section::make('Langkah berikutnya')
                    ->description('Alur sukses pertama: aplikasi, koneksi, uji, salin konfigurasi.')
                    ->schema([
                        Placeholder::make('create_credential')
                            ->label('1. Hubungkan WhatsApp')
                            ->content(fn (string $operation): string => $operation === 'edit'
                                ? 'Tombol Hubungkan WhatsApp. Pilih nomor sendiri atau provider. Routing dibuat otomatis.'
                                : 'Simpan aplikasi, lalu Hubungkan WhatsApp.'),
                        Placeholder::make('choose_route')
                            ->label('2. Kirim pesan uji')
                            ->content('Setelah koneksi siap, kirim tes dari halaman koneksi. Tidak perlu membuat aturan rute dulu.'),
                        Placeholder::make('activate_application')
                            ->label('3. Salin integrasi')
                            ->content('Tab Kredensial API → Hubungkan aplikasi untuk WAG_URL dan WAG_TOKEN. Aturan Rute tetap tersedia sebagai pengaturan lanjutan.'),
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
            ->searchPlaceholder('Cari nama atau ID aplikasi')
            ->filters([
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
            ->emptyStateHeading('Belum ada aplikasi klien')
            ->emptyStateDescription('Buat aplikasi, hubungkan WhatsApp, kirim uji, lalu salin WAG_URL dan WAG_TOKEN.')
            ->emptyStateIcon(Heroicon::OutlinedComputerDesktop)
            ->emptyStateActions([
                Action::make('create')
                    ->label('Tambah aplikasi klien')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(static::getUrl('create')),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<int, Column|Component>
     */
    public static function tableColumns(): array
    {
        return [
            static::logoColumn(),
            TextColumn::make('name')
                ->label('Nama')
                ->searchable()
                ->sortable()
                ->description(fn (ClientApplication $record): string => $record->slug),
            TextColumn::make('website_url')
                ->label('URL')
                ->url(fn (ClientApplication $record): ?string => $record->website_url, shouldOpenInNewTab: true)
                ->limit(40)
                ->placeholder('—')
                ->toggleable(),
            TextColumn::make('slug')
                ->label('ID aplikasi')
                ->copyable()
                ->searchable()
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('is_active')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (mixed $state): string => $state ? 'Aktif' : 'Nonaktif')
                ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
            TextColumn::make('rate_limit_per_minute')
                ->label('Batas/menit')
                ->numeric()
                ->sortable(),
            TextColumn::make('api_credentials_count')
                ->label('Token')
                ->counts('apiCredentials'),
            TextColumn::make('routing_policies_count')
                ->label('Rute')
                ->counts('routingPolicies'),
            TextColumn::make('updated_at')
                ->label('Diperbarui')
                ->dateTime()
                ->sortable()
                ->toggleable(isToggledHiddenByDefault: true),
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
                    static::logoColumn()->grow(false),
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
                TextColumn::make('slug')
                    ->copyable()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('website_url')
                    ->url(fn (ClientApplication $record): ?string => $record->website_url, shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedGlobeAlt)
                    ->limit(40)
                    ->color('primary'),
                Split::make([
                    TextColumn::make('api_credentials_count')
                        ->counts('apiCredentials')
                        ->icon(Heroicon::OutlinedKey)
                        ->formatStateUsing(fn (mixed $state): string => $state.' token'),
                    TextColumn::make('routing_policies_count')
                        ->counts('routingPolicies')
                        ->icon(Heroicon::OutlinedQueueList)
                        ->formatStateUsing(fn (mixed $state): string => $state.' rute'),
                    TextColumn::make('rate_limit_per_minute')
                        ->numeric()
                        ->sortable()
                        ->icon(Heroicon::OutlinedBolt)
                        ->formatStateUsing(fn (mixed $state): string => $state.'/menit'),
                ]),
            ])->space(3),
        ];
    }

    public static function logoColumn(): ImageColumn
    {
        return ImageColumn::make('logo_path')
            ->label('Logo')
            ->disk(ClientApplication::LOGO_DISK)
            ->visibility('public')
            ->circular()
            ->imageSize(36)
            ->defaultImageUrl(fn (ClientApplication $record): string => static::initialsAvatar($record->name));
    }

    /**
     * A local SVG with the application's initial, so applications without a logo stay recognisable
     * without calling an external avatar service.
     */
    public static function initialsAvatar(string $name): string
    {
        $initial = mb_strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1));
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#0f766e"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" font-family="sans-serif" font-size="30" fill="#ffffff">'
            .htmlspecialchars($initial, ENT_QUOTES | ENT_XML1)
            .'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Hapus')
            ->modalHeading('Hapus aplikasi klien?')
            ->modalDescription(function (ClientApplication $record): string {
                $routeCount = $record->routingPolicies()->count();
                $credentialCount = $record->apiCredentials()->whereNull('revoked_at')->count();
                $parts = ['Aplikasi akan dinonaktifkan. Token API tidak bisa dipakai lagi. Riwayat pesan tetap tersimpan.'];

                if ($credentialCount > 0) {
                    $parts[] = $credentialCount.' kredensial aktif akan dicabut.';
                }

                if ($routeCount > 0) {
                    $parts[] = $routeCount.' aturan rute aplikasi ini ikut dihapus.';
                }

                $parts[] = 'ID aplikasi dapat dipakai ulang.';

                return implode(' ', $parts);
            })
            ->modalSubmitActionLabel('Hapus aplikasi')
            ->successNotificationTitle('Aplikasi klien dihapus');
    }

    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->label('Hapus yang dipilih')
            ->modalHeading('Hapus aplikasi klien yang dipilih?')
            ->modalDescription('Aplikasi yang dipilih akan dinonaktifkan. Token API dicabut, aturan rute ikut dihapus, dan riwayat pesan tetap tersimpan.')
            ->modalSubmitActionLabel('Hapus')
            ->successNotificationTitle('Aplikasi klien dihapus');
    }

    public static function createRoutingPolicyAction(): Action
    {
        return Action::make('createRoutingPolicy')
            ->label('Buat aturan rute')
            ->icon(Heroicon::OutlinedQueueList)
            ->color('gray')
            ->url(fn (ClientApplication $record): string => RoutingPolicyResource::getUrl('create').'?client_application_id='.$record->getKey());
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
