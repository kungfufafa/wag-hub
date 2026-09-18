<?php

namespace App\Filament\Pages;

use App\Filament\Support\PanelNavigation;
use App\Models\AlertSetting;
use App\Services\Alerts\AlertTestSender;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class ManageAlertSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::ALERTS;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Pengaturan Alert';

    protected static ?string $title = 'Pengaturan Alert';

    protected static ?string $slug = 'alert-settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function fillForm(): void
    {
        $record = AlertSetting::current();

        $this->form->fill([
            'is_enabled' => $record->is_enabled,
            'cooldown_seconds' => $record->cooldown_seconds,
            'smtp_host' => $record->smtp_host,
            'smtp_port' => $record->smtp_port,
            'smtp_username' => $record->smtp_username,
            'smtp_password' => null,
            'smtp_encryption' => $record->smtp_encryption,
            'smtp_from_address' => $record->smtp_from_address,
            'smtp_from_name' => $record->smtp_from_name,
            'telegram_bot_token' => null,
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model(AlertSetting::current());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Umum')
                    ->description('Saklar utama dan jeda pengiriman ulang alert.')
                    ->schema([
                        Toggle::make('is_enabled')
                            ->label('Alert aktif')
                            ->helperText('Uji kirim email/Telegram tetap bisa dijalankan meski saklar ini mati.')
                            ->required(),
                        TextInput::make('cooldown_seconds')
                            ->label('Cooldown (detik)')
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(86400)
                            ->helperText('Jeda minimum antar alert untuk provider yang sama (semua perubahan status).'),
                    ])
                    ->columns(['md' => 2]),
                Section::make('SMTP')
                    ->description('Konfigurasi pengiriman email alert. Secret kosong saat simpan = tidak diubah.')
                    ->schema([
                        TextInput::make('smtp_host')
                            ->label('Host SMTP')
                            ->maxLength(255),
                        TextInput::make('smtp_port')
                            ->label('Port')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(65535),
                        TextInput::make('smtp_username')
                            ->label('Username')
                            ->maxLength(255),
                        TextInput::make('smtp_password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->helperText('Kosongkan bila ingin mempertahankan password yang ada.')
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                            ->dehydrated(fn (?string $state): bool => filled($state)),
                        Select::make('smtp_encryption')
                            ->label('Enkripsi')
                            ->options([
                                'tls' => 'TLS',
                                'ssl' => 'SSL',
                            ])
                            ->nullable(),
                        TextInput::make('smtp_from_address')
                            ->label('Alamat pengirim')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('smtp_from_name')
                            ->label('Nama pengirim')
                            ->maxLength(255),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Telegram')
                    ->description('Bot token untuk channel Telegram. Secret kosong saat simpan = tidak diubah.')
                    ->schema([
                        TextInput::make('telegram_bot_token')
                            ->label('Bot token')
                            ->password()
                            ->revealable()
                            ->helperText('Kosongkan bila ingin mempertahankan token yang ada.')
                            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
                            ->dehydrated(fn (?string $state): bool => filled($state)),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
            ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make($this->getFormActions())
                    ->key('form-actions'),
            ]);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan')
                ->submit('save')
                ->keyBindings(['mod+s']),
        ];
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('testEmail')
                ->label('Kirim email uji')
                ->icon(Heroicon::OutlinedEnvelope)
                ->color('gray')
                ->schema([
                    TextInput::make('destination')
                        ->label('Alamat email tujuan')
                        ->email()
                        ->required(),
                ])
                ->action(function (array $data, AlertTestSender $sender): void {
                    try {
                        $sender->sendEmail((string) $data['destination']);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Email uji gagal')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Email uji terkirim')
                        ->success()
                        ->send();
                }),
            Action::make('testTelegram')
                ->label('Kirim Telegram uji')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->schema([
                    TextInput::make('chat_id')
                        ->label('Chat ID')
                        ->required(),
                ])
                ->action(function (array $data, AlertTestSender $sender): void {
                    try {
                        $sender->sendTelegram((string) $data['chat_id']);
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Telegram uji gagal')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Telegram uji terkirim')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $record = AlertSetting::current();
        $record->fill($data);
        $record->save();

        Notification::make()
            ->title('Pengaturan alert disimpan')
            ->success()
            ->send();

        $this->fillForm();
    }
}
