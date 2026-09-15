<?php

namespace App\Filament\Resources\ProviderAccounts;

use App\Filament\Pages\WhatsAppInbox;
use App\Filament\Resources\ProviderAccounts\Pages\CreateProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\EditProviderAccount;
use App\Filament\Resources\ProviderAccounts\Pages\ListProviderAccounts;
use App\Filament\Support\ConfigurationListLayout;
use App\Filament\Support\SyncsSlugFromName;
use App\Models\ProviderAccount;
use App\Services\ProviderAccountTester;
use App\Support\PhoneNormalizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\Layout\Component;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use InvalidArgumentException;
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
                                ->placeholder('Contoh: WAHA Utama')
                                ->required()
                                ->maxLength(120)
                                ->live(onBlur: true)
                                ->afterStateUpdated(SyncsSlugFromName::afterStateUpdated()),
                            TextInput::make('slug')
                                ->label('ID provider')
                                ->placeholder('waha-utama')
                                ->helperText('Diisi otomatis dari nama. Boleh diubah sebelum disimpan.')
                                ->alphaDash()
                                ->maxLength(80)
                                ->unique(
                                    ignoreRecord: true,
                                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->withoutTrashed(),
                                )
                                ->validationMessages([
                                    'unique' => 'ID provider ini sudah dipakai.',
                                    'alpha_dash' => 'Gunakan huruf kecil, angka, dan tanda hubung.',
                                ]),
                            Select::make('driver')
                                ->label('Driver')
                                ->helperText('Form koneksi di bawah menyesuaikan pilihan ini.')
                                ->options([
                                    'waha' => 'WAHA',
                                    'fonnte' => 'Fonnte',
                                    'gowa' => 'GOWA',
                                    'waba' => 'WABA (Meta Cloud API)',
                                ])
                                ->required()
                                ->native(false)
                                ->live(),
                            TextInput::make('timeout_seconds')
                                ->label('Timeout (detik)')
                                ->helperText('Batas waktu menunggu jawaban provider.')
                                ->numeric()
                                ->required()
                                ->minValue(1)
                                ->maxValue(60)
                                ->default(15),
                            Toggle::make('is_active')
                                ->label('Provider aktif')
                                ->helperText('Nonaktifkan agar dilewati saat pengiriman, tanpa menghapus akun.')
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
                            TextInput::make('configuration.attachment_max_bytes')
                                ->label('Batas attachment (byte)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(16777216)
                                ->default(4 * 1024 * 1024)
                                ->helperText('Kebijakan awal Fonnte 4 MB; naikkan setelah batas akun terverifikasi.')
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
                            TextInput::make('configuration.version')
                                ->label('Versi GOWA')
                                ->placeholder('8.10.0')
                                ->helperText('Wajib dikonfigurasi minimal 8.10.0 untuk mengirim dokumen melalui URL.')
                                ->maxLength(32)
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
                    Section::make('Webhook pesan masuk')
                        ->description('Arahkan webhook provider ke URL ini supaya inbox menerima pesan, bukan hanya kirim.')
                        ->schema([
                            Placeholder::make('inbox_webhook_url')
                                ->label('URL webhook')
                                ->content(function (?ProviderAccount $record): string {
                                    if ($record === null || blank($record->uuid)) {
                                        return 'Simpan akun dulu. URL webhook akan muncul setelah akun tersimpan.';
                                    }

                                    return url('/webhooks/whatsapp/'.$record->uuid);
                                }),
                            TextInput::make('configuration.webhook_secret')
                                ->label('Token webhook (opsional)')
                                ->password()
                                ->revealable()
                                ->helperText('Jika diisi, kirim sebagai query token= atau header X-Webhook-Token. Untuk WABA, pakai nilai yang sama sebagai Verify Token. Kosongkan saat mengubah untuk mempertahankan token yang ada.')
                                ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                                ->dehydrated(fn (?string $state): bool => filled($state)),
                        ]),
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
                            ->content(fn (string $operation): string => $operation === 'edit'
                                ? 'Uji koneksi dari tombol Uji di atas, lalu masukkan akun ini ke aturan rute.'
                                : 'Setelah disimpan, uji koneksi dari daftar Akun Provider lewat aksi Uji (kirim pesan atau cek nomor).'),
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
            ->searchPlaceholder('Cari nama atau ID provider')
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
                static::inboxAction(),
                static::testAction(),
                EditAction::make(),
                static::deleteAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::deleteBulkAction(),
                ]),
            ])
            ->emptyStateHeading('Belum ada akun provider')
            ->emptyStateDescription('Tambah koneksi WAHA, Fonnte, GOWA, atau WABA, lalu uji sebelum dipakai di aturan rute.')
            ->emptyStateIcon(Heroicon::OutlinedServerStack)
            ->emptyStateActions([
                Action::make('create')
                    ->label('Tambah akun provider')
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
                ->sortable()
                ->description(fn (ProviderAccount $record): string => $record->slug),
            TextColumn::make('driver')
                ->label('Driver')
                ->badge()
                ->formatStateUsing(fn (string $state): string => strtoupper($state)),
            TextColumn::make('health_status')
                ->label('Kesehatan')
                ->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'unknown' => 'Belum diuji',
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
            TextColumn::make('is_active')
                ->label('Status')
                ->badge()
                ->formatStateUsing(fn (mixed $state): string => $state ? 'Aktif' : 'Nonaktif')
                ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
            TextColumn::make('consecutive_failures')
                ->label('Kegagalan')
                ->numeric()
                ->sortable(),
            TextColumn::make('circuit_open_until')
                ->label('Istirahat hingga')
                ->dateTime()
                ->placeholder('Siap dikirim'),
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
                Split::make([
                    TextColumn::make('driver')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => strtoupper($state)),
                    TextColumn::make('health_status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'unknown' => 'Belum diuji',
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
                ]),
                TextColumn::make('slug')
                    ->copyable()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('consecutive_failures')
                    ->numeric()
                    ->sortable()
                    ->formatStateUsing(function (mixed $state, ProviderAccount $record): string {
                        if ($record->circuit_open_until?->isFuture()) {
                            return 'Istirahat sampai '.$record->circuit_open_until
                                ->timezone(config('app.timezone'))
                                ->format('H.i');
                        }

                        $failures = (int) $state;

                        return $failures > 0
                            ? $failures.' gagal beruntun'
                            : 'Siap dikirim';
                    })
                    ->color(function (mixed $state, ProviderAccount $record): string {
                        if ($record->circuit_open_until?->isFuture()) {
                            return 'warning';
                        }

                        return ((int) $state) > 0 ? 'danger' : 'gray';
                    }),
            ])->space(3),
        ];
    }

    public static function inboxAction(): Action
    {
        return Action::make('inbox')
            ->label('Inbox')
            ->icon(Heroicon::OutlinedInbox)
            ->color('gray')
            ->url(fn (ProviderAccount $record): string => WhatsAppInbox::getUrl().'?provider='.$record->getKey());
    }

    public static function testAction(): Action
    {
        return Action::make('test')
            ->label('Uji')
            ->icon(Heroicon::OutlinedBeaker)
            ->color('gray')
            ->slideOver()
            ->modalWidth(Width::Medium)
            ->modalHeading(fn (ProviderAccount $record): string => "Uji provider {$record->name}")
            ->modalDescription('Panggil driver akun ini langsung. Hasil disimpan sebagai jejak uji admin dan memperbarui kesehatan provider.')
            ->modalSubmitActionLabel('Jalankan uji')
            ->schema(fn (ProviderAccount $record): array => [
                Select::make('type')
                    ->label('Jenis uji')
                    ->options(
                        $record->driver === 'waba'
                            ? ['send' => 'Kirim pesan']
                            : [
                                'send' => 'Kirim pesan',
                                'check_number' => 'Cek nomor',
                            ],
                    )
                    ->default('send')
                    ->required()
                    ->live()
                    ->columnSpanFull(),
                TextInput::make('recipient')
                    ->label('Nomor tujuan')
                    ->tel()
                    ->required()
                    ->maxLength(32)
                    ->helperText('Contoh: 081234567890 atau 6281234567890')
                    ->rule(function (): \Closure {
                        return function (string $attribute, mixed $value, \Closure $fail): void {
                            try {
                                app(PhoneNormalizer::class)->normalize((string) $value);
                            } catch (InvalidArgumentException $exception) {
                                $fail($exception->getMessage());
                            }
                        };
                    })
                    ->columnSpanFull(),
                Textarea::make('body')
                    ->label('Isi pesan')
                    ->rows(3)
                    ->default('Pesan uji dari Gateway Hub.')
                    ->maxLength(10000)
                    ->visible(fn (Get $get): bool => $get('type') === 'send')
                    ->required(fn (Get $get): bool => $get('type') === 'send')
                    ->columnSpanFull(),
            ])
            ->action(function (ProviderAccount $record, array $data, ProviderAccountTester $tester): void {
                try {
                    $result = match ($data['type']) {
                        'check_number' => $tester->checkNumber(
                            $record,
                            (string) $data['recipient'],
                            auth()->id(),
                        ),
                        default => $tester->send(
                            $record,
                            (string) $data['recipient'],
                            (string) ($data['body'] ?? ''),
                            auth()->id(),
                        ),
                    };
                } catch (InvalidArgumentException $exception) {
                    Notification::make()
                        ->title('Uji provider gagal')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $notification = Notification::make()
                    ->title($result->title)
                    ->body($result->body);

                if ($result->success) {
                    $notification->success()->send();
                } else {
                    $notification->danger()->send();
                }
            });
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Hapus')
            ->modalHeading('Hapus akun provider?')
            ->modalDescription(function (ProviderAccount $record): string {
                $stepCount = $record->routingSteps()->count();
                $parts = ['Akun akan dinonaktifkan dan tidak dipakai pengiriman. Riwayat pesan tetap tersimpan.'];

                if ($stepCount > 0) {
                    $parts[] = "Akun ini dipakai di {$stepCount} langkah rute; langkah tersebut akan dilewati.";
                }

                $parts[] = 'ID provider dapat dipakai ulang.';

                return implode(' ', $parts);
            })
            ->modalSubmitActionLabel('Hapus akun')
            ->successNotificationTitle('Akun provider dihapus');
    }

    public static function deleteBulkAction(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->label('Hapus yang dipilih')
            ->modalHeading('Hapus akun provider yang dipilih?')
            ->modalDescription('Akun yang dipilih akan dinonaktifkan dan dilewati saat pengiriman. Riwayat pesan tetap tersimpan.')
            ->modalSubmitActionLabel('Hapus')
            ->successNotificationTitle('Akun provider dihapus');
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
