<?php

namespace App\Filament\Resources\WhatsAppConnections;

use App\Domain\Connections\ConnectionStatus;
use App\Domain\Connections\ConnectionType;
use App\Filament\Pages\ConnectWhatsApp;
use App\Filament\Resources\WhatsAppConnections\Pages\ListWhatsAppConnections;
use App\Filament\Resources\WhatsAppConnections\Pages\ViewWhatsAppConnection;
use App\Filament\Support\PanelNavigation;
use App\Models\WhatsAppConnection;
use App\Services\Connections\ConnectionHealthProjector;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class WhatsAppConnectionResource extends Resource
{
    protected static ?string $model = WhatsAppConnection::class;

    protected static ?string $slug = 'connections';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::WHATSAPP;

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Koneksi WhatsApp';

    protected static ?string $modelLabel = 'Koneksi WhatsApp';

    protected static ?string $pluralModelLabel = 'Koneksi WhatsApp';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Koneksi')
                    ->searchable()
                    ->sortable()
                    ->description(fn (WhatsAppConnection $record): string => $record->clientApplication?->name ?? ''),
                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ConnectionType::from($state)->label()),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => ConnectionStatus::from($state)->color())
                    ->formatStateUsing(fn (string $state): string => ConnectionStatus::from($state)->label()),
                TextColumn::make('is_default')
                    ->label('Default')
                    ->badge()
                    ->formatStateUsing(fn (mixed $state): string => $state ? 'Ya' : 'Tidak')
                    ->color(fn (mixed $state): string => $state ? 'success' : 'gray'),
                TextColumn::make('recommended_action')
                    ->label('Langkah berikutnya')
                    ->placeholder('Siap dipakai'),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->since()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Jenis')
                    ->options([
                        ConnectionType::ManagedNumber->value => ConnectionType::ManagedNumber->label(),
                        ConnectionType::ProviderRoute->value => ConnectionType::ProviderRoute->label(),
                    ]),
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ConnectionStatus::cases())->mapWithKeys(
                        fn (ConnectionStatus $status): array => [$status->value => $status->label()],
                    )->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateHeading('Belum ada koneksi WhatsApp')
            ->emptyStateDescription('Hubungkan nomor WhatsApp atau provider, kirim uji, lalu salin WAG_URL dan WAG_TOKEN.')
            ->emptyStateIcon(Heroicon::OutlinedSignal)
            ->emptyStateActions([
                Action::make('connect')
                    ->label('Hubungkan WhatsApp')
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(ConnectWhatsApp::getUrl()),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppConnections::route('/'),
            'view' => ViewWhatsAppConnection::route('/{record}'),
        ];
    }

    public static function refresh(WhatsAppConnection $connection): WhatsAppConnection
    {
        return app(ConnectionHealthProjector::class)->hydrate($connection);
    }
}
