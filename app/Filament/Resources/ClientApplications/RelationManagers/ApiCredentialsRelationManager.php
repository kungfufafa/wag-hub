<?php

namespace App\Filament\Resources\ClientApplications\RelationManagers;

use App\Models\ApiCredential;
use App\Models\ClientApplication;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ApiCredentialsRelationManager extends RelationManager
{
    protected static string $relationship = 'apiCredentials';

    protected static ?string $title = 'API credentials';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('token_prefix')
                    ->label('Token prefix')
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('abilities')
                    ->badge()
                    ->separator(','),
                TextColumn::make('last_used_at')
                    ->dateTime()
                    ->placeholder('Never'),
                TextColumn::make('expires_at')
                    ->dateTime()
                    ->placeholder('No expiry'),
                TextColumn::make('revoked_at')
                    ->label('Status')
                    ->formatStateUsing(fn ($state): string => $state ? 'Revoked' : 'Active')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'danger' : 'success'),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label('Issue credential')
                    ->icon(Heroicon::OutlinedKey)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120),
                        CheckboxList::make('abilities')
                            ->options([
                                'messages:send' => 'Send messages',
                                'messages:read' => 'Read message status',
                            ])
                            ->default(['messages:send', 'messages:read'])
                            ->required()
                            ->minItems(1),
                        DateTimePicker::make('expires_at')
                            ->label('Expires at')
                            ->after('now')
                            ->seconds(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var ClientApplication $application */
                        $application = $this->getOwnerRecord();
                        $issued = ApiCredential::issue(
                            $application,
                            $data['name'],
                            $data['abilities'],
                        );

                        if (filled($data['expires_at'] ?? null)) {
                            $issued->credential->update(['expires_at' => $data['expires_at']]);
                        }

                        Notification::make()
                            ->title('Credential created')
                            ->body($issued->plainTextToken)
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ApiCredential $record): bool => $record->revoked_at === null)
                    ->action(function (ApiCredential $record): void {
                        $record->update(['revoked_at' => now()]);

                        Notification::make()
                            ->title('Credential revoked')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
