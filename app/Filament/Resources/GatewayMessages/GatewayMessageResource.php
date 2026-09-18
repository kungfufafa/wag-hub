<?php

namespace App\Filament\Resources\GatewayMessages;

use App\Filament\Resources\GatewayMessages\Pages\ListGatewayMessages;
use App\Filament\Resources\GatewayMessages\Pages\ViewGatewayMessage;
use App\Filament\Support\CopiesToClipboard;
use App\Filament\Support\PanelNavigation;
use App\Models\GatewayMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class GatewayMessageResource extends Resource
{
    protected static ?string $model = GatewayMessage::class;

    protected static ?string $slug = 'messages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::WHATSAPP;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Pesan';

    protected static ?string $modelLabel = 'Pesan';

    protected static ?string $pluralModelLabel = 'Pesan';

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('ID pesan')
                    ->limit(13)
                    ->tooltip(fn (GatewayMessage $record): string => $record->uuid)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('clientApplication.name')
                    ->label('Aplikasi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('recipient_last4')
                    ->label('Penerima')
                    ->formatStateUsing(fn (?string $state): string => '••••••••'.($state ?? '')),
                TextColumn::make('purpose')
                    ->label('Tujuan')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::purposeLabel($state)),
                TextColumn::make('origin')
                    ->label('Asal')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === 'inbox' ? 'Inbox' : 'API'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state)),
                TextColumn::make('acceptedProviderAccount.name')
                    ->label('Provider')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('client_application_id')
                    ->label('Aplikasi')
                    ->relationship('clientApplication', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'queued' => 'Antrian',
                        'processing' => 'Diproses',
                        'provider_accepted' => 'Diterima provider',
                        'failed' => 'Gagal',
                        'outcome_unknown' => 'Belum dapat dipastikan',
                        'expired' => 'Kedaluwarsa',
                        'dead_letter' => 'Dihentikan',
                    ]),
                SelectFilter::make('purpose')
                    ->label('Tujuan')
                    ->options([
                        'otp' => 'OTP',
                        'transactional' => 'Transaksional',
                        'notification' => 'Notifikasi',
                        'admin_test' => 'Uji admin',
                    ]),
                SelectFilter::make('accepted_provider_account_id')
                    ->label('Provider yang menerima')
                    ->relationship('acceptedProviderAccount', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->label('Tanggal dibuat')
                    ->schema([
                        DatePicker::make('from')->label('Dari'),
                        DatePicker::make('until')->label('Sampai'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                        )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->recordUrl(fn (GatewayMessage $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns([
                'default' => 1,
                'lg' => 3,
            ])
            ->components([
                Section::make('Ringkasan')
                    ->description(fn (GatewayMessage $record): string => static::statusExplanation($record))
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => static::statusColor($state))
                            ->formatStateUsing(fn (string $state): string => static::statusLabel($state)),
                        TextEntry::make('purpose')
                            ->label('Tujuan')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (?string $state): string => static::purposeLabel($state)),
                        TextEntry::make('origin')
                            ->label('Asal')
                            ->formatStateUsing(fn (?string $state): string => $state === 'inbox' ? 'Inbox dashboard' : 'API'),
                        TextEntry::make('originUser.name')
                            ->label('Administrator')
                            ->placeholder('—'),
                        TextEntry::make('clientApplication.name')
                            ->label('Aplikasi')
                            ->placeholder('Tidak diketahui'),
                        TextEntry::make('recipient_last4')
                            ->label('Penerima')
                            ->formatStateUsing(fn (?string $state): string => '••••••••'.($state ?? '')),
                        TextEntry::make('acceptedProviderAccount.name')
                            ->label('Provider yang menerima')
                            ->placeholder('Belum ada'),
                        TextEntry::make('pinnedProviderAccount.name')
                            ->label('Provider pilihan')
                            ->placeholder('—'),
                        TextEntry::make('inbox_chat_id')
                            ->label('Chat ID')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime(),
                        TextEntry::make('expires_at')
                            ->label('Batas waktu')
                            ->dateTime()
                            ->visible(fn (GatewayMessage $record): bool => $record->expires_at !== null)
                            ->color(fn (GatewayMessage $record): string => $record->expires_at?->isPast() ? 'danger' : 'gray'),
                        TextEntry::make('uuid')
                            ->label('ID pesan')
                            ->copyable(),
                        TextEntry::make('last_error_message')
                            ->label('Alasan')
                            ->placeholder('—')
                            ->visible(fn (GatewayMessage $record): bool => filled($record->last_error_message))
                            ->columnSpanFull(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 2,
                    ]),
                Section::make('Langkah berikutnya')
                    ->description(fn (GatewayMessage $record): string => static::guidanceHeading($record))
                    ->schema([
                        Text::make(fn (GatewayMessage $record): string => static::guidanceBody($record))
                            ->columnSpanFull(),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 1,
                    ]),
                Section::make('Isi pesan')
                    ->description('Salin teks ini, lalu tempel di WhatsApp.')
                    ->headerActions([
                        Action::make('copyForWhatsApp')
                            ->label('Salin untuk WhatsApp')
                            ->icon(Heroicon::OutlinedClipboardDocument)
                            ->alpineClickHandler(fn (GatewayMessage $record): string => CopiesToClipboard::alpine($record->plaintextBody())),
                    ])
                    ->schema([
                        SchemaView::make('filament.gateway-messages.message-body')
                            ->viewData(fn (GatewayMessage $record): array => [
                                'body' => $record->plaintextBody(),
                                'attachment' => $record->outboundAttachment(),
                            ]),
                    ])
                    ->columnSpanFull(),
                Section::make('Perjalanan')
                    ->description('Tahap yang sudah terjadi, dari dibuat sampai hasil terakhir.')
                    ->schema([
                        RepeatableEntry::make('lifecycle')
                            ->hiddenLabel()
                            ->getStateUsing(fn (GatewayMessage $record): array => static::lifecycleTimeline($record))
                            ->table([
                                TableColumn::make('Tahap'),
                                TableColumn::make('Waktu'),
                            ])
                            ->schema([
                                TextEntry::make('label')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'Dibuat', 'Masuk antrian' => 'gray',
                                        'Diproses' => 'info',
                                        'Diterima provider' => 'success',
                                        'Gagal', 'Dihentikan', 'Kedaluwarsa' => 'danger',
                                        'Belum dapat dipastikan' => 'warning',
                                        default => 'gray',
                                    }),
                                TextEntry::make('at')
                                    ->dateTime()
                                    ->placeholder('—'),
                            ]),
                    ])
                    ->columnSpanFull(),
                Section::make('Percobaan')
                    ->description('Urutan provider yang dicoba. Yang paling atas dicoba lebih dulu.')
                    ->schema([
                        RepeatableEntry::make('attempts')
                            ->hiddenLabel()
                            ->placeholder('Belum ada percobaan ke provider.')
                            ->table([
                                TableColumn::make('#'),
                                TableColumn::make('Provider'),
                                TableColumn::make('Hasil'),
                                TableColumn::make('Lanjutan'),
                                TableColumn::make('Keterangan'),
                                TableColumn::make('Waktu'),
                            ])
                            ->schema([
                                TextEntry::make('sequence'),
                                TextEntry::make('providerAccount.name')
                                    ->placeholder('Tidak ditemukan'),
                                TextEntry::make('status')
                                    ->badge()
                                    ->color(fn (?string $state): string => static::attemptStatusColor($state))
                                    ->formatStateUsing(fn (?string $state): string => static::attemptStatusLabel($state)),
                                TextEntry::make('retry_disposition')
                                    ->formatStateUsing(fn (?string $state): string => static::retryDispositionLabel($state)),
                                TextEntry::make('error_message')
                                    ->placeholder('—')
                                    ->limit(120),
                                TextEntry::make('finished_at')
                                    ->dateTime()
                                    ->placeholder('—'),
                            ]),
                    ])
                    ->columnSpanFull(),
                Section::make('Detail teknis')
                    ->description('ID pendukung dan jejak event untuk audit.')
                    ->collapsed()
                    ->schema([
                        TextEntry::make('correlation_id')
                            ->label('ID korelasi')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('client_reference')
                            ->label('Referensi klien')
                            ->placeholder('—'),
                        TextEntry::make('mode')
                            ->label('Cara kirim')
                            ->badge()
                            ->color('gray')
                            ->formatStateUsing(fn (?string $state): string => match ($state) {
                                'sync' => 'Langsung',
                                'async' => 'Antrian',
                                default => $state ?? '—',
                            }),
                        TextEntry::make('route_key')
                            ->label('Kunci rute')
                            ->placeholder('—'),
                        TextEntry::make('provider_message_id')
                            ->label('ID pesan provider')
                            ->placeholder('—'),
                        TextEntry::make('last_error_code')
                            ->label('Kode error')
                            ->placeholder('—'),
                        RepeatableEntry::make('events')
                            ->label('Jejak event')
                            ->placeholder('Belum ada event.')
                            ->table([
                                TableColumn::make('Waktu'),
                                TableColumn::make('Kejadian'),
                                TableColumn::make('Sumber'),
                            ])
                            ->schema([
                                TextEntry::make('occurred_at')
                                    ->dateTime(),
                                TextEntry::make('type')
                                    ->badge()
                                    ->color(fn (?string $state): string => static::eventColor($state))
                                    ->formatStateUsing(fn (?string $state): string => static::eventLabel($state)),
                                TextEntry::make('source')
                                    ->badge()
                                    ->color('gray')
                                    ->formatStateUsing(fn (?string $state): string => static::eventSourceLabel($state)),
                            ])
                            ->columnSpanFull(),
                        RepeatableEntry::make('attempts')
                            ->label('Rincian teknis percobaan')
                            ->placeholder('Belum ada percobaan.')
                            ->table([
                                TableColumn::make('#'),
                                TableColumn::make('Provider'),
                                TableColumn::make('Kepastian'),
                                TableColumn::make('HTTP'),
                                TableColumn::make('Latensi'),
                                TableColumn::make('ID remote'),
                                TableColumn::make('Kode error'),
                            ])
                            ->schema([
                                TextEntry::make('sequence'),
                                TextEntry::make('providerAccount.name')
                                    ->placeholder('Tidak ditemukan'),
                                TextEntry::make('delivery_certainty')
                                    ->badge()
                                    ->color(fn (?string $state): string => static::deliveryCertaintyColor($state))
                                    ->formatStateUsing(fn (?string $state): string => static::deliveryCertaintyLabel($state)),
                                TextEntry::make('http_status')
                                    ->placeholder('—'),
                                TextEntry::make('latency_ms')
                                    ->suffix(' ms')
                                    ->placeholder('—'),
                                TextEntry::make('provider_message_id')
                                    ->placeholder('—'),
                                TextEntry::make('error_code')
                                    ->placeholder('—'),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return list<array{label: string, at: mixed}>
     */
    public static function lifecycleTimeline(GatewayMessage $record): array
    {
        $steps = [];

        foreach ([
            ['label' => 'Dibuat', 'at' => $record->created_at],
            ['label' => 'Masuk antrian', 'at' => $record->queued_at],
            ['label' => 'Diproses', 'at' => $record->processing_at],
            ['label' => 'Diterima provider', 'at' => $record->provider_accepted_at],
            ['label' => 'Gagal', 'at' => $record->failed_at],
            ['label' => 'Belum dapat dipastikan', 'at' => $record->outcome_unknown_at],
            ['label' => 'Dihentikan', 'at' => $record->dead_lettered_at],
            ['label' => 'Kedaluwarsa', 'at' => $record->status === 'expired' ? $record->expires_at : null],
        ] as $step) {
            if ($step['at'] !== null) {
                $steps[] = $step;
            }
        }

        return $steps;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'clientApplication',
            'acceptedProviderAccount',
            'attempts.providerAccount',
            'events',
        ]);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGatewayMessages::route('/'),
            'view' => ViewGatewayMessage::route('/{record}'),
        ];
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'provider_accepted' => 'success',
            'queued', 'outcome_unknown' => 'warning',
            'processing' => 'info',
            'failed', 'dead_letter', 'expired' => 'danger',
            default => 'gray',
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => 'Antrian',
            'processing' => 'Diproses',
            'provider_accepted' => 'Diterima provider',
            'failed' => 'Gagal',
            'outcome_unknown' => 'Belum dapat dipastikan',
            'expired' => 'Kedaluwarsa',
            'dead_letter' => 'Dihentikan',
            default => (string) str($status)->replace('_', ' ')->title(),
        };
    }

    public static function statusExplanation(GatewayMessage $record): string
    {
        return match ($record->status) {
            'queued' => 'Pesan menunggu giliran untuk dikirim.',
            'processing' => 'Hub sedang menghubungi provider WhatsApp.',
            'provider_accepted' => 'Provider sudah menerima pesan ini.',
            'failed' => 'Provider tidak berhasil mengirim pesan.',
            'outcome_unknown' => 'Tidak yakin apakah provider menerima pesan.',
            'expired' => 'Batas waktu kirim sudah lewat.',
            'dead_letter' => 'Hub berhenti mencoba mengirim pesan ini.',
            default => 'Periksa status dan jejak percobaan di bawah.',
        };
    }

    public static function guidanceHeading(GatewayMessage $record): string
    {
        return match ($record->status) {
            'queued' => 'Menunggu pengiriman',
            'processing' => 'Sedang dikirim',
            'provider_accepted' => 'Tidak perlu tindakan',
            'failed' => $record->isSafeToRetry() ? 'Bisa dikirim ulang' : 'Tidak bisa dikirim ulang',
            'outcome_unknown' => $record->isSafeToRetry() ? 'Perlu dicek sebelum kirim ulang' : 'Tidak bisa dikirim ulang',
            'expired' => 'Sudah kedaluwarsa',
            'dead_letter' => 'Pengiriman dihentikan',
            default => 'Periksa status pesan',
        };
    }

    public static function guidanceBody(GatewayMessage $record): string
    {
        return match ($record->status) {
            'queued' => 'Pesan menunggu giliran. Muat ulang halaman jika status belum berubah.',
            'processing' => 'Hub sedang menghubungi provider. Muat ulang untuk melihat hasilnya.',
            'provider_accepted' => 'Provider sudah menerima pesan. Tidak perlu kirim ulang.',
            'failed' => $record->isSafeToRetry()
                ? 'Pengiriman gagal. Gunakan tombol Coba kirim ulang di atas. Riwayat percobaan lama tetap tersimpan.'
                : 'Pesan gagal dan sudah melewati batas waktu. Minta aplikasi sumber mengirim pesan baru.',
            'outcome_unknown' => $record->isSafeToRetry()
                ? 'Tidak dapat dipastikan apakah provider menerima pesan. Kirim ulang berisiko penerima mendapat pesan ganda. Gunakan tombol Coba kirim ulang hanya jika Anda yakin.'
                : 'Hasil pengiriman tidak pasti dan pesan sudah melewati batas waktu. Jangan kirim ulang dari sini.',
            'expired' => 'Batas waktu pesan sudah lewat. Aplikasi sumber harus mengirim pesan baru dengan kunci idempotensi baru.',
            'dead_letter' => 'Hub berhenti mencoba. Periksa akun provider, lalu kirim pesan baru dari aplikasi sumber.',
            default => 'Muat ulang halaman jika status belum sesuai harapan.',
        };
    }

    public static function purposeLabel(?string $purpose): string
    {
        return match ($purpose) {
            'otp' => 'OTP',
            'transactional' => 'Transaksional',
            'notification' => 'Notifikasi',
            'admin_test' => 'Uji admin',
            default => $purpose ?? '—',
        };
    }

    public static function attemptStatusLabel(?string $status): string
    {
        return match ($status) {
            'started' => 'Sedang dicoba',
            'accepted' => 'Diterima',
            'rejected' => 'Ditolak',
            'provider_failed' => 'Provider gagal',
            'outcome_unknown' => 'Belum dapat dipastikan',
            'skipped' => 'Dilewati',
            default => $status ?? '—',
        };
    }

    public static function attemptStatusColor(?string $status): string
    {
        return match ($status) {
            'accepted' => 'success',
            'started' => 'info',
            'rejected', 'provider_failed' => 'danger',
            'outcome_unknown' => 'warning',
            'skipped' => 'gray',
            default => 'gray',
        };
    }

    public static function deliveryCertaintyLabel(?string $certainty): string
    {
        return match ($certainty) {
            'accepted' => 'Diterima provider',
            'not_sent' => 'Belum terkirim',
            'unknown' => 'Belum dapat dipastikan',
            default => $certainty ?? '—',
        };
    }

    public static function deliveryCertaintyColor(?string $certainty): string
    {
        return match ($certainty) {
            'accepted' => 'success',
            'not_sent' => 'danger',
            'unknown' => 'warning',
            default => 'gray',
        };
    }

    public static function retryDispositionLabel(?string $disposition): string
    {
        return match ($disposition) {
            'fallback_allowed' => 'Coba provider cadangan',
            'do_not_retry' => 'Selesai',
            'reconcile_only' => 'Perlu pengecekan manual',
            default => $disposition ?? '—',
        };
    }

    public static function retryDispositionColor(?string $disposition): string
    {
        return match ($disposition) {
            'fallback_allowed' => 'warning',
            'do_not_retry' => 'gray',
            'reconcile_only' => 'info',
            default => 'gray',
        };
    }

    public static function eventLabel(?string $event): string
    {
        return match ($event) {
            'queued' => 'Masuk antrian',
            'processing' => 'Mulai diproses',
            'attempt_started' => 'Mencoba provider',
            'attempt_skipped' => 'Provider dilewati',
            'fallback_started' => 'Beralih ke provider cadangan',
            'provider_accepted', 'recovery_provider_accepted' => 'Diterima provider',
            'failed', 'recovery_failed', 'recovery_sync_failed_before_attempt' => 'Pengiriman gagal',
            'outcome_unknown', 'recovery_outcome_unknown' => 'Belum dapat dipastikan',
            'expired' => 'Pesan kedaluwarsa',
            'manual_retry_queued', 'recovery_requeued' => 'Dimasukkan ke antrian ulang',
            default => $event ?? '—',
        };
    }

    public static function eventColor(?string $event): string
    {
        return match ($event) {
            'provider_accepted', 'recovery_provider_accepted' => 'success',
            'failed', 'recovery_failed', 'recovery_sync_failed_before_attempt', 'expired' => 'danger',
            'outcome_unknown', 'recovery_outcome_unknown', 'fallback_started', 'manual_retry_queued', 'recovery_requeued' => 'warning',
            'processing', 'attempt_started' => 'info',
            'queued', 'attempt_skipped' => 'gray',
            default => 'gray',
        };
    }

    public static function eventSourceLabel(?string $source): string
    {
        return match ($source) {
            'admin' => 'Admin',
            'worker', 'system' => 'Sistem',
            'api' => 'Aplikasi',
            default => $source ?? '—',
        };
    }
}
