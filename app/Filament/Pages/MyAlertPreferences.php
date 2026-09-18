<?php

namespace App\Filament\Pages;

use App\Filament\Support\PanelNavigation;
use App\Models\UserAlertPreference;
use App\Services\Alerts\AlertTestSender;
use BackedEnum;
use Filament\Actions\Action;
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
use Illuminate\Support\Facades\Auth;
use Throwable;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class MyAlertPreferences extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::ALERTS;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Preferensi saya';

    protected static ?string $title = 'Preferensi Alert';

    protected static ?string $slug = 'my-alert-preferences';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->fillForm();
    }

    protected function preference(): UserAlertPreference
    {
        return UserAlertPreference::query()->firstOrNew([
            'user_id' => Auth::id(),
        ]);
    }

    protected function fillForm(): void
    {
        $record = $this->preference();

        $this->form->fill([
            'telegram_enabled' => (bool) $record->telegram_enabled,
            'telegram_chat_id' => $record->telegram_chat_id,
            'email_enabled' => (bool) $record->email_enabled,
            'email_address' => $record->email_address,
        ]);
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->model($this->preference());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Telegram')
                    ->description('Preferensi penerima alert via Telegram untuk akun Anda.')
                    ->schema([
                        Toggle::make('telegram_enabled')
                            ->label('Telegram aktif')
                            ->required(),
                        TextInput::make('telegram_chat_id')
                            ->label('Chat ID')
                            ->maxLength(255),
                    ])
                    ->columns(['md' => 2]),
                Section::make('Email')
                    ->description('Preferensi penerima alert via email untuk akun Anda.')
                    ->schema([
                        Toggle::make('email_enabled')
                            ->label('Email aktif')
                            ->required(),
                        TextInput::make('email_address')
                            ->label('Alamat email')
                            ->email()
                            ->maxLength(255),
                    ])
                    ->columns(['md' => 2]),
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
                ->action(function (AlertTestSender $sender): void {
                    $destination = (string) ($this->data['email_address'] ?? '');

                    if (! filled($destination)) {
                        Notification::make()
                            ->title('Email uji gagal')
                            ->body('Isi alamat email di form terlebih dahulu.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $sender->sendEmail($destination);
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
                ->action(function (AlertTestSender $sender): void {
                    $chatId = (string) ($this->data['telegram_chat_id'] ?? '');

                    if (! filled($chatId)) {
                        Notification::make()
                            ->title('Telegram uji gagal')
                            ->body('Isi Chat ID di form terlebih dahulu.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $sender->sendTelegram($chatId);
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
        $record = $this->preference();
        $record->fill($data);
        $record->user_id = Auth::id();
        $record->save();

        Notification::make()
            ->title('Preferensi alert disimpan')
            ->success()
            ->send();

        $this->fillForm();
    }
}
