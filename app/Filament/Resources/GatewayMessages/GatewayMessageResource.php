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

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Messages';

    protected static ?string $modelLabel = 'message';

    protected static ?string $pluralModelLabel = 'messages';

    protected static ?string $recordTitleAttribute = 'uuid';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('uuid')
                    ->label('Message ID')
                    ->limit(13)
                    ->tooltip(fn (GatewayMessage $record): string => $record->uuid)
                    ->copyable()
                    ->searchable(),
                TextColumn::make('clientApplication.name')
                    ->label('Application')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('recipient_last4')
                    ->label('Recipient')
                    ->formatStateUsing(fn (?string $state): string => '••••••••'.($state ?? '')),
                TextColumn::make('purpose')
                    ->badge(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => static::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => (string) str($state)->replace('_', ' ')->title()),
                TextColumn::make('acceptedProviderAccount.name')
                    ->label('Provider')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('client_application_id')
                    ->label('Application')
                    ->relationship('clientApplication', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('status')->options([
                    'queued' => 'Queued',
                    'processing' => 'Processing',
                    'provider_accepted' => 'Provider accepted',
                    'failed' => 'Failed',
                    'outcome_unknown' => 'Outcome unknown',
                    'expired' => 'Expired',
                    'dead_letter' => 'Dead letter',
                ]),
                SelectFilter::make('purpose')->options([
                    'otp' => 'OTP',
                    'transactional' => 'Transactional',
                    'notification' => 'Notification',
                ]),
                SelectFilter::make('accepted_provider_account_id')
                    ->label('Accepted provider')
                    ->relationship('acceptedProviderAccount', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
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
        return $schema->components([
            Section::make('Message')
                ->schema([
                    TextEntry::make('uuid')
                        ->label('Message ID')
                        ->copyable(),
                    TextEntry::make('correlation_id')
                        ->label('Correlation ID')
                        ->copyable(),
                    TextEntry::make('clientApplication.name')
                        ->label('Application'),
                    TextEntry::make('recipient_last4')
                        ->label('Recipient')
                        ->formatStateUsing(fn (?string $state): string => '••••••••'.($state ?? '')),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state): string => static::statusColor($state))
                        ->formatStateUsing(fn (string $state): string => (string) str($state)->replace('_', ' ')->title()),
                    TextEntry::make('purpose')->badge(),
                    TextEntry::make('mode')->badge(),
                    TextEntry::make('route_key')->label('Route key'),
                    TextEntry::make('client_reference')
                        ->label('Client reference')
                        ->placeholder('—'),
                    TextEntry::make('acceptedProviderAccount.name')
                        ->label('Accepted provider')
                        ->placeholder('—'),
                    TextEntry::make('provider_message_id')
                        ->label('Provider message ID')
                        ->placeholder('—'),
                    TextEntry::make('last_error_code')
                        ->label('Last error code')
                        ->placeholder('—'),
                    TextEntry::make('last_error_message')
                        ->label('Last error')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(2),
            Section::make('Lifecycle')
                ->schema([
                    TextEntry::make('created_at')->dateTime(),
                    TextEntry::make('queued_at')->dateTime()->placeholder('—'),
                    TextEntry::make('processing_at')->dateTime()->placeholder('—'),
                    TextEntry::make('provider_accepted_at')->dateTime()->placeholder('—'),
                    TextEntry::make('failed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('outcome_unknown_at')->dateTime()->placeholder('—'),
                    TextEntry::make('dead_lettered_at')->dateTime()->placeholder('—'),
                    TextEntry::make('expires_at')->dateTime()->placeholder('No expiry'),
                ])
                ->columns(2),
            Section::make('Provider attempts')
                ->schema([
                    RepeatableEntry::make('attempts')
                        ->label('')
                        ->schema([
                            TextEntry::make('sequence')->label('#'),
                            TextEntry::make('providerAccount.name')->label('Provider'),
                            TextEntry::make('status')->badge(),
                            TextEntry::make('delivery_certainty')->label('Certainty')->badge(),
                            TextEntry::make('retry_disposition')->label('Retry disposition')->badge(),
                            TextEntry::make('http_status')->label('HTTP')->placeholder('—'),
                            TextEntry::make('latency_ms')->label('Latency')->suffix(' ms')->placeholder('—'),
                            TextEntry::make('provider_message_id')->label('Remote ID')->placeholder('—'),
                            TextEntry::make('error_code')->label('Error code')->placeholder('—'),
                            TextEntry::make('error_message')->label('Error')->placeholder('—')->columnSpanFull(),
                            TextEntry::make('started_at')->dateTime(),
                            TextEntry::make('finished_at')->dateTime()->placeholder('—'),
                        ])
                        ->columns(3),
                ]),
            Section::make('Event timeline')
                ->schema([
                    RepeatableEntry::make('events')
                        ->label('')
                        ->schema([
                            TextEntry::make('occurred_at')->label('When')->dateTime(),
                            TextEntry::make('type')->badge(),
                            TextEntry::make('source')->badge(),
                        ])
                        ->columns(3),
                ]),
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
}
