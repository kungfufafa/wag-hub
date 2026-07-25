<?php

namespace App\Filament\Resources\AlertDeliveries;

use App\Filament\Resources\AlertDeliveries\Pages\ListAlertDeliveries;
use App\Filament\Resources\AlertDeliveries\Pages\ViewAlertDelivery;
use App\Models\AlertDelivery;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
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

class AlertDeliveryResource extends Resource
{
    protected static ?string $model = AlertDelivery::class;

    protected static ?string $slug = 'alert-deliveries';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Operasi';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Riwayat Alert';

    protected static ?string $modelLabel = 'Pengiriman Alert';

    protected static ?string $pluralModelLabel = 'Riwayat Alert';

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('ID')
                    ->limit(13)
                    ->tooltip(fn (AlertDelivery $record): string => $record->uuid)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('providerAccount.name')
                    ->label('Provider')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('user.name')
                    ->label('Penerima')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('channel')
                    ->label('Kanal')
                    ->badge(),
                TextColumn::make('from_status')
                    ->label('Dari')
                    ->badge()
                    ->color(fn (string $state): string => static::healthStatusColor($state)),
                TextColumn::make('to_status')
                    ->label('Ke')
                    ->badge()
                    ->color(fn (string $state): string => static::healthStatusColor($state)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => static::deliveryStatusColor($state))
                    ->formatStateUsing(fn (string $state): string => static::deliveryStatusLabel($state)),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('channel')
                    ->label('Kanal')
                    ->options([
                        'telegram' => 'Telegram',
                        'email' => 'Email',
                    ]),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options([
                        'pending' => 'Menunggu',
                        'sent' => 'Terkirim',
                        'failed' => 'Gagal',
                        'skipped_cooldown' => 'Dilewati (cooldown)',
                    ]),
                SelectFilter::make('provider_account_id')
                    ->label('Provider')
                    ->relationship('providerAccount', 'name')
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
            ->recordUrl(fn (AlertDelivery $record): string => static::getUrl('view', ['record' => $record]))
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
                Section::make('Ringkasan pengiriman')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => static::deliveryStatusColor($state))
                            ->formatStateUsing(fn (string $state): string => static::deliveryStatusLabel($state)),
                        TextEntry::make('channel')
                            ->label('Kanal')
                            ->badge(),
                        TextEntry::make('providerAccount.name')
                            ->label('Provider')
                            ->placeholder('—'),
                        TextEntry::make('user.name')
                            ->label('Penerima')
                            ->placeholder('—'),
                        TextEntry::make('from_status')
                            ->label('Status dari')
                            ->badge()
                            ->color(fn (string $state): string => static::healthStatusColor($state)),
                        TextEntry::make('to_status')
                            ->label('Status ke')
                            ->badge()
                            ->color(fn (string $state): string => static::healthStatusColor($state)),
                        TextEntry::make('consecutive_failures')
                            ->label('Kegagalan beruntun'),
                        TextEntry::make('circuit_open_until')
                            ->label('Circuit terbuka hingga')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('error_summary')
                            ->label('Ringkasan error')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('failure_reason')
                            ->label('Alasan gagal')
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('uuid')
                            ->label('ID pengiriman')
                            ->copyable(),
                        TextEntry::make('sent_at')
                            ->label('Terkirim')
                            ->dateTime()
                            ->placeholder('—'),
                        TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime(),
                    ])
                    ->columns([
                        'default' => 1,
                        'md' => 2,
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
            'index' => ListAlertDeliveries::route('/'),
            'view' => ViewAlertDelivery::route('/{record}'),
        ];
    }

    public static function deliveryStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => 'Menunggu',
            'sent' => 'Terkirim',
            'failed' => 'Gagal',
            'skipped_cooldown' => 'Dilewati (cooldown)',
            default => (string) str($status)->replace('_', ' ')->title(),
        };
    }

    public static function deliveryStatusColor(string $status): string
    {
        return match ($status) {
            'sent' => 'success',
            'pending' => 'info',
            'skipped_cooldown' => 'warning',
            'failed' => 'danger',
            default => 'gray',
        };
    }

    public static function healthStatusColor(string $status): string
    {
        return match ($status) {
            'healthy' => 'success',
            'degraded' => 'warning',
            'unavailable' => 'danger',
            default => 'gray',
        };
    }
}
