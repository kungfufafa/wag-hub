<?php

namespace App\Filament\Resources\NumberCheckRequests;

use App\Filament\Resources\NumberCheckRequests\Pages\ListNumberCheckRequests;
use App\Filament\Resources\NumberCheckRequests\Pages\ViewNumberCheckRequest;
use App\Filament\Support\PanelNavigation;
use App\Models\NumberCheckRequest;
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

class NumberCheckRequestResource extends Resource
{
    protected static ?string $model = NumberCheckRequest::class;

    protected static ?string $slug = 'number-checks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::WHATSAPP;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Pengecekan Nomor';

    protected static ?string $modelLabel = 'Pengecekan Nomor';

    protected static ?string $pluralModelLabel = 'Pengecekan Nomor';

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('ID audit')
                    ->limit(13)
                    ->tooltip(fn (NumberCheckRequest $record): string => $record->uuid)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('clientApplication.name')
                    ->label('Aplikasi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('apiCredential.name')
                    ->label('Credential')
                    ->searchable(),
                TextColumn::make('recipient_last4')
                    ->label('Nomor')
                    ->formatStateUsing(fn (?string $state): string => '••••••••'.($state ?? '')),
                TextColumn::make('route_key')
                    ->label('Rute')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => static::statusLabel($state)),
                TextColumn::make('resolvedProviderAccount.name')
                    ->label('Provider hasil')
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
                        'registered' => 'Terdaftar',
                        'not_registered' => 'Tidak terdaftar',
                        'unknown' => 'Tidak diketahui',
                        'unsupported' => 'Tidak didukung',
                        'failed' => 'Gagal',
                    ]),
                SelectFilter::make('resolved_provider_account_id')
                    ->label('Provider hasil')
                    ->relationship('resolvedProviderAccount', 'name')
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
            ->recordUrl(fn (NumberCheckRequest $record): string => static::getUrl('view', ['record' => $record]))
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
                Section::make('Ringkasan audit')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => static::statusColor($state))
                            ->formatStateUsing(fn (string $state): string => static::statusLabel($state)),
                        TextEntry::make('recipient_last4')
                            ->label('Nomor')
                            ->formatStateUsing(fn (?string $state): string => '••••••••'.($state ?? '')),
                        TextEntry::make('clientApplication.name')
                            ->label('Aplikasi'),
                        TextEntry::make('apiCredential.name')
                            ->label('Credential peminta'),
                        TextEntry::make('route_key')
                            ->label('Kunci rute')
                            ->badge(),
                        TextEntry::make('routingPolicy.name')
                            ->label('Policy')
                            ->placeholder('Tidak ditemukan'),
                        TextEntry::make('resolvedProviderAccount.name')
                            ->label('Provider hasil')
                            ->placeholder('Belum ada'),
                        TextEntry::make('uuid')
                            ->label('ID audit')
                            ->copyable(),
                        TextEntry::make('correlation_id')
                            ->label('ID korelasi')
                            ->copyable(),
                        TextEntry::make('last_error_code')
                            ->label('Kode error')
                            ->placeholder('—'),
                        TextEntry::make('started_at')
                            ->label('Mulai')
                            ->dateTime(),
                        TextEntry::make('finished_at')
                            ->label('Selesai')
                            ->dateTime()
                            ->placeholder('—'),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
                    ])
                    ->columnSpanFull(),
                Section::make('Percobaan provider')
                    ->description('Urutan provider yang diperiksa, dilewati, atau tidak mendukung lookup.')
                    ->schema([
                        RepeatableEntry::make('attempts')
                            ->hiddenLabel()
                            ->schema([
                                TextEntry::make('sequence')->label('#'),
                                TextEntry::make('providerAccount.name')->label('Provider'),
                                TextEntry::make('status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (?string $state): string => static::statusColor($state ?? ''))
                                    ->formatStateUsing(fn (?string $state): string => static::statusLabel($state ?? '')),
                                TextEntry::make('http_status')
                                    ->label('HTTP')
                                    ->placeholder('—'),
                                TextEntry::make('latency_ms')
                                    ->label('Latensi')
                                    ->suffix(' ms')
                                    ->placeholder('—'),
                                TextEntry::make('reason_code')
                                    ->label('Alasan')
                                    ->placeholder('—'),
                                TextEntry::make('finished_at')
                                    ->label('Selesai')
                                    ->dateTime(),
                            ])
                            ->columns(4)
                            ->contained(false),
                    ])
                    ->columnSpanFull(),
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
            'index' => ListNumberCheckRequests::route('/'),
            'view' => ViewNumberCheckRequest::route('/{record}'),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'processing' => 'Diproses',
            'registered' => 'Terdaftar',
            'not_registered' => 'Tidak terdaftar',
            'unknown' => 'Tidak diketahui',
            'unsupported' => 'Tidak didukung',
            'skipped' => 'Dilewati',
            'failed' => 'Gagal',
            default => (string) str($status)->replace('_', ' ')->title(),
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            'registered' => 'success',
            'processing' => 'info',
            'unknown', 'unsupported' => 'warning',
            'not_registered', 'failed' => 'danger',
            default => 'gray',
        };
    }
}
