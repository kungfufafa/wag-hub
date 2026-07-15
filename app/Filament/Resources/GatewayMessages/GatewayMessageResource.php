<?php

namespace App\Filament\Resources\GatewayMessages;

use App\Filament\Resources\GatewayMessages\Pages\ListGatewayMessages;
use App\Filament\Resources\GatewayMessages\Pages\ViewGatewayMessage;
use App\Models\GatewayMessage;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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

    protected static string|UnitEnum|null $navigationGroup = 'Operasi';

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
                        'outcome_unknown' => 'Hasil tidak diketahui',
                        'expired' => 'Kedaluwarsa',
                        'dead_letter' => 'Dead letter',
                    ]),
                SelectFilter::make('purpose')
                    ->label('Tujuan')
                    ->options([
                        'otp' => 'OTP',
                        'transactional' => 'Transaksional',
                        'notification' => 'Notifikasi',
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
                Section::make('Pesan')
                    ->schema([
                        TextEntry::make('uuid')
                            ->label('ID pesan')
                            ->copyable(),
                        TextEntry::make('correlation_id')
                            ->label('ID korelasi')
                            ->copyable(),
                        TextEntry::make('clientApplication.name')
                            ->label('Aplikasi'),
                        TextEntry::make('recipient_last4')
                            ->label('Penerima')
                            ->formatStateUsing(fn (?string $state): string => '••••••••'.($state ?? '')),
                        TextEntry::make('body')
                            ->label('Isi pesan')
                            ->copyable()
                            ->placeholder('—')
                            ->columnSpanFull(),
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
                        TextEntry::make('mode')
                            ->label('Mode')
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('route_key')->label('Kunci rute'),
                        TextEntry::make('client_reference')
                            ->label('Referensi klien')
                            ->placeholder('—'),
                        TextEntry::make('acceptedProviderAccount.name')
                            ->label('Provider yang menerima')
                            ->placeholder('—'),
                        TextEntry::make('provider_message_id')
                            ->label('ID pesan provider')
                            ->placeholder('—'),
                        TextEntry::make('last_error_code')
                            ->label('Kode error terakhir')
                            ->placeholder('—'),
                        TextEntry::make('last_error_message')
                            ->label('Error terakhir')
                            ->placeholder('—')
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
                Section::make('Siklus hidup')
                    ->description('Tahap yang sudah terjadi, urut waktu.')
                    ->schema([
                        RepeatableEntry::make('lifecycle')
                            ->label('')
                            ->getStateUsing(fn (GatewayMessage $record): array => static::lifecycleTimeline($record))
                            ->schema([
                                TextEntry::make('label')
                                    ->label('Tahap')
                                    ->badge()
                                    ->color(fn (?string $state): string => match ($state) {
                                        'Dibuat', 'Masuk antrian', 'Kedaluwarsa' => 'gray',
                                        'Diproses' => 'info',
                                        'Diterima provider' => 'success',
                                        'Gagal', 'Dead letter' => 'danger',
                                        'Hasil tidak diketahui' => 'warning',
                                        default => 'gray',
                                    }),
                                TextEntry::make('at')
                                    ->label('Waktu')
                                    ->dateTime()
                                    ->placeholder('Tanpa batas'),
                            ])
                            ->columns(1)
                            ->contained(false),
                    ])
                    ->columnSpan([
                        'default' => 'full',
                        'lg' => 1,
                    ]),
                Section::make('Percobaan provider')
                    ->schema([
                        RepeatableEntry::make('attempts')
                            ->label('')
                            ->schema([
                                TextEntry::make('sequence')->label('#'),
                                TextEntry::make('providerAccount.name')->label('Provider'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (?string $state): string => static::attemptStatusColor($state))
                                    ->formatStateUsing(fn (?string $state): string => static::attemptStatusLabel($state)),
                                TextEntry::make('delivery_certainty')
                                    ->label('Hasil provider')
                                    ->badge()
                                    ->color(fn (?string $state): string => static::deliveryCertaintyColor($state))
                                    ->formatStateUsing(fn (?string $state): string => static::deliveryCertaintyLabel($state)),
                                TextEntry::make('retry_disposition')
                                    ->label('Tindak lanjut')
                                    ->badge()
                                    ->color(fn (?string $state): string => static::retryDispositionColor($state))
                                    ->formatStateUsing(fn (?string $state): string => static::retryDispositionLabel($state)),
                                TextEntry::make('http_status')->label('HTTP')->placeholder('—'),
                                TextEntry::make('latency_ms')->label('Latensi')->suffix(' ms')->placeholder('—'),
                                TextEntry::make('provider_message_id')->label('ID remote')->placeholder('—'),
                                TextEntry::make('error_code')->label('Kode error')->placeholder('—'),
                                TextEntry::make('error_message')->label('Error')->placeholder('—')->columnSpanFull(),
                                TextEntry::make('started_at')->label('Mulai')->dateTime(),
                                TextEntry::make('finished_at')->label('Selesai')->dateTime()->placeholder('—'),
                            ])
                            ->columns(3),
                    ])
                    ->columnSpanFull(),
                Section::make('Linimasa event')
                    ->schema([
                        RepeatableEntry::make('events')
                            ->label('')
                            ->schema([
                                TextEntry::make('occurred_at')->label('Waktu')->dateTime(),
                                TextEntry::make('type')
                                    ->label('Proses')
                                    ->badge()
                                    ->color(fn (?string $state): string => static::eventColor($state))
                                    ->formatStateUsing(fn (?string $state): string => static::eventLabel($state)),
                                TextEntry::make('source')
                                    ->label('Sumber')
                                    ->badge()
                                    ->color('gray'),
                            ])
                            ->columns(3),
                    ])
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
            ['label' => 'Hasil tidak diketahui', 'at' => $record->outcome_unknown_at],
            ['label' => 'Dead letter', 'at' => $record->dead_lettered_at],
        ] as $step) {
            if ($step['at'] !== null) {
                $steps[] = $step;
            }
        }

        $steps[] = [
            'label' => 'Kedaluwarsa',
            'at' => $record->expires_at,
        ];

        return $steps;
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
            'outcome_unknown' => 'Hasil tidak diketahui',
            'expired' => 'Kedaluwarsa',
            'dead_letter' => 'Dead letter',
            default => (string) str($status)->replace('_', ' ')->title(),
        };
    }

    public static function purposeLabel(?string $purpose): string
    {
        return match ($purpose) {
            'otp' => 'OTP',
            'transactional' => 'Transaksional',
            'notification' => 'Notifikasi',
            default => $purpose ?? '—',
        };
    }

    public static function attemptStatusLabel(?string $status): string
    {
        return match ($status) {
            'started' => 'Dicoba',
            'accepted' => 'Diterima provider',
            'provider_failed' => 'Provider gagal',
            'outcome_unknown' => 'Hasil tidak diketahui',
            'skipped' => 'Dilewati',
            default => $status ?? '—',
        };
    }

    public static function attemptStatusColor(?string $status): string
    {
        return match ($status) {
            'accepted' => 'success',
            'started' => 'info',
            'provider_failed' => 'danger',
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
            'outcome_unknown', 'recovery_outcome_unknown' => 'Hasil tidak diketahui',
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
}
