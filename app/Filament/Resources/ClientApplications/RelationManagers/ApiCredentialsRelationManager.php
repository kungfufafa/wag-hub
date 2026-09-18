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
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Js;
use Illuminate\Validation\Rules\Unique;

class ApiCredentialsRelationManager extends RelationManager
{
    protected static string $relationship = 'apiCredentials';

    protected static ?string $title = 'Kredensial API';

    public function showIssuedTokenAction(): Action
    {
        return Action::make('showIssuedToken')
            ->modalHeading('Kredensial berhasil dibuat')
            ->modalDescription('Salin token sekarang. Setelah modal ditutup, token tidak dapat dilihat lagi.')
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalIconColor('success')
            ->schema([
                TextInput::make('token')
                    ->label('Token API')
                    ->password()
                    ->revealable()
                    ->readOnly()
                    ->dehydrated(false)
                    ->extraInputAttributes([
                        'class' => 'font-mono',
                    ])
                    ->suffixAction(
                        Action::make('copyToken')
                            ->label('Salin')
                            ->icon(Heroicon::ClipboardDocumentList)
                            ->color('gray')
                            ->alpineClickHandler(function (mixed $state): string {
                                return static::copyToClipboardAlpine((string) ($state ?? ''));
                            }),
                    ),
            ])
            ->fillForm(fn (array $arguments): array => [
                'token' => $arguments['token'] ?? '',
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Selesai');
    }

    protected static function copyToClipboardAlpine(string $text): string
    {
        $token = Js::from($text);

        return <<<JS
            (() => {
                const modal = (typeof \$el !== 'undefined')
                    ? (\$el.closest('.fi-modal-window') || \$el.closest('.fi-modal') || \$el.closest('[role=dialog]'))
                    : null

                const text = {$token}

                if (! text) {
                    return
                }

                const notify = () => {
                    try {
                        \$tooltip('Token disalin', {
                            theme: \$store.theme,
                            timeout: 1500,
                        })
                    } catch (e) {}
                }

                // Filament modals trap focus, so a textarea on document.body cannot be
                // focused and execCommand('copy') returns true while copying nothing.
                const copyWithModalTextarea = () => {
                    if (! modal) {
                        return false
                    }

                    const el = document.createElement('textarea')
                    el.value = text
                    el.setAttribute('readonly', '')
                    el.style.cssText = 'position:fixed;top:0;left:-9999px;font-size:12pt;'
                    modal.appendChild(el)
                    el.focus()
                    el.select()
                    el.setSelectionRange(0, el.value.length)

                    const focused = document.activeElement === el
                        && el.selectionEnd === el.value.length

                    let ok = false

                    try {
                        ok = focused && Boolean(document.execCommand('copy'))
                    } finally {
                        el.remove()
                    }

                    return ok
                }

                const copyWithEvent = () => {
                    let ok = false
                    const onCopy = (event) => {
                        if (! event.clipboardData) {
                            return
                        }

                        event.clipboardData.setData('text/plain', text)
                        event.preventDefault()
                        ok = true
                    }

                    document.addEventListener('copy', onCopy, true)

                    try {
                        document.execCommand('copy')
                    } finally {
                        document.removeEventListener('copy', onCopy, true)
                    }

                    return ok
                }

                if (copyWithModalTextarea() || copyWithEvent()) {
                    notify()
                    return
                }

                if (window.isSecureContext && window.navigator.clipboard?.writeText) {
                    window.navigator.clipboard.writeText(text).then(notify).catch(() => {})
                }
            })()
            JS;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('token_prefix')
                    ->label('Prefiks token')
                    ->fontFamily('mono')
                    ->copyable(),
                TextColumn::make('abilities')
                    ->label('Hak akses')
                    ->badge()
                    ->separator(','),
                TextColumn::make('last_used_at')
                    ->label('Terakhir dipakai')
                    ->dateTime()
                    ->placeholder('Belum pernah'),
                TextColumn::make('expires_at')
                    ->label('Kedaluwarsa')
                    ->dateTime()
                    ->placeholder('Tanpa batas'),
                TextColumn::make('revoked_at')
                    ->label('Status')
                    ->formatStateUsing(fn ($state): string => $state ? 'Dicabut' : 'Aktif')
                    ->badge()
                    ->color(fn ($state): string => $state ? 'danger' : 'success'),
            ])
            ->headerActions([
                Action::make('issue')
                    ->label('Terbitkan kredensial')
                    ->icon(Heroicon::OutlinedKey)
                    ->slideOver()
                    ->modalWidth(Width::Medium)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->required()
                            ->maxLength(120)
                            ->unique(
                                table: ApiCredential::class,
                                column: 'name',
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                                    'client_application_id',
                                    $this->getOwnerRecord()->getKey(),
                                ),
                            )
                            ->validationMessages([
                                'unique' => 'Nama kredensial ini sudah dipakai untuk aplikasi ini.',
                            ]),
                        CheckboxList::make('abilities')
                            ->label('Hak akses')
                            ->options([
                                'messages:send' => 'Kirim pesan',
                                'messages:read' => 'Baca status pesan',
                                'numbers:check' => 'Cek nomor WhatsApp',
                                'engine:use' => 'Engine (tautkan nomor sendiri)',
                            ])
                            ->default(['messages:send', 'messages:read'])
                            ->required()
                            ->minItems(1),
                        DateTimePicker::make('expires_at')
                            ->label('Kedaluwarsa pada')
                            ->after('now')
                            ->seconds(false),
                    ])
                    ->action(function (array $data): void {
                        /** @var ClientApplication $application */
                        $application = $this->getOwnerRecord();

                        try {
                            $issued = ApiCredential::issue(
                                $application,
                                $data['name'],
                                $data['abilities'],
                            );
                        } catch (UniqueConstraintViolationException) {
                            Notification::make()
                                ->title('Nama kredensial sudah dipakai')
                                ->body('Gunakan nama lain untuk aplikasi ini.')
                                ->danger()
                                ->send();

                            return;
                        }

                        if (filled($data['expires_at'] ?? null)) {
                            $issued->credential->update(['expires_at' => $data['expires_at']]);
                        }

                        $this->replaceMountedAction('showIssuedToken', [
                            'token' => $issued->plainTextToken,
                        ]);
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Cabut')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ApiCredential $record): bool => $record->revoked_at === null)
                    ->action(function (ApiCredential $record): void {
                        $record->update(['revoked_at' => now()]);

                        Notification::make()
                            ->title('Kredensial dicabut')
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
