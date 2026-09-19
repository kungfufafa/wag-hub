<?php

namespace App\Filament\Resources\WhatsAppConnections;

use App\Domain\Connection\ConnectionStatus;
use App\Domain\Connection\ConnectionType;
use App\Filament\Resources\WhatsAppConnections\Pages\ConnectWhatsApp;
use App\Filament\Resources\WhatsAppConnections\Pages\ListWhatsAppConnections;
use App\Filament\Resources\WhatsAppConnections\Pages\ViewWhatsAppConnection;
use App\Filament\Support\PanelNavigation;
use App\Models\WhatsAppConnection;
use App\Services\Connection\ConnectionPresenter;
use App\Services\Connection\ConnectionStatusResolver;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class WhatsAppConnectionResource extends Resource
{
    protected static ?string $model = WhatsAppConnection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::APPS;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Koneksi WhatsApp';

    protected static ?string $modelLabel = 'Koneksi WhatsApp';

    protected static ?string $pluralModelLabel = 'Koneksi WhatsApp';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('clientApplication.name')
                    ->label('Aplikasi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama koneksi')
                    ->searchable()
                    ->weight(FontWeight::SemiBold),
                TextColumn::make('type')
                    ->label('Tipe')
                    ->formatStateUsing(fn (string $state): string => ConnectionType::tryFrom($state)?->label() ?? $state)
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state): string => ConnectionStatus::tryFrom($state)?->label() ?? $state)
                    ->badge()
                    ->color(fn (string $state): string => match (ConnectionStatus::tryFrom($state)) {
                        ConnectionStatus::Ready => 'success',
                        ConnectionStatus::Connecting, ConnectionStatus::SetupRequired => 'warning',
                        ConnectionStatus::Degraded => 'warning',
                        ConnectionStatus::Error, ConnectionStatus::Disconnected => 'danger',
                        default => 'gray',
                    }),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('sender_identity.phone')
                    ->label('Nomor')
                    ->placeholder('—'),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->since(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(ConnectionStatus::cases())->mapWithKeys(
                        fn (ConnectionStatus $status): array => [$status->value => $status->label()],
                    )->all()),
                SelectFilter::make('type')
                    ->options(collect(ConnectionType::cases())->mapWithKeys(
                        fn (ConnectionType $type): array => [$type->value => $type->label()],
                    )->all()),
            ])
            ->recordActions([
                Action::make('refresh')
                    ->label('Segarkan status')
                    ->icon(Heroicon::ArrowPath)
                    ->action(function (WhatsAppConnection $record): void {
                        app(ConnectionStatusResolver::class)->refresh($record);
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWhatsAppConnections::route('/'),
            'connect' => ConnectWhatsApp::route('/connect'),
            'view' => ViewWhatsAppConnection::route('/{record}'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function presentRecord(WhatsAppConnection $connection): array
    {
        $connection = app(ConnectionStatusResolver::class)->refresh($connection);

        return app(ConnectionPresenter::class)->toArray($connection, includeSetupSecrets: true);
    }
}
