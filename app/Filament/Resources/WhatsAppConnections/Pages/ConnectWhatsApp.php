<?php

namespace App\Filament\Resources\WhatsAppConnections\Pages;

use App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource;
use App\Models\ClientApplication;
use App\Services\Connection\ConnectionProvisioner;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class ConnectWhatsApp extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = WhatsAppConnectionResource::class;

    protected static ?string $title = 'Hubungkan WhatsApp';

    protected static ?string $navigationLabel = 'Hubungkan WhatsApp';

    protected static ?string $slug = 'connect';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.connect-whatsapp';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'client_application_id' => ClientApplication::query()->orderBy('name')->value('id'),
            'connection_type' => 'managed_number',
            'name' => 'WhatsApp Utama',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Langkah 1 · Pilih aplikasi')
                    ->schema([
                        Select::make('client_application_id')
                            ->label('Aplikasi')
                            ->options(fn (): array => ClientApplication::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->required()
                            ->native(false),
                    ]),
                Section::make('Langkah 2 · Pilih cara menghubungkan')
                    ->schema([
                        Select::make('connection_type')
                            ->label('Tipe koneksi')
                            ->options([
                                'managed_number' => 'Gunakan nomor WhatsApp saya (QR / pairing)',
                                'provider_route' => 'Gunakan provider eksternal',
                            ])
                            ->required()
                            ->live()
                            ->native(false),
                        TextInput::make('name')
                            ->label('Nama koneksi')
                            ->required()
                            ->maxLength(120),
                    ]),
                Section::make('Langkah 3 · Provider eksternal')
                    ->visible(fn (callable $get): bool => $get('connection_type') === 'provider_route')
                    ->schema([
                        Select::make('driver')
                            ->label('Provider')
                            ->options([
                                'fonnte' => 'Fonnte',
                                'waha' => 'WAHA',
                                'gowa' => 'GOWA',
                                'waba' => 'WABA (Meta)',
                                'wag_hub' => 'WAG Hub (bawaan)',
                            ])
                            ->required()
                            ->native(false),
                        TextInput::make('configuration.endpoint')
                            ->label('Endpoint / Base URL')
                            ->visible(fn (callable $get): bool => in_array($get('driver'), ['fonnte', 'waha', 'gowa', 'waba'], true)),
                        TextInput::make('configuration.token')
                            ->label('Token / API Key')
                            ->password()
                            ->revealable(),
                    ]),
            ]);
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('create')
                ->label('Buat koneksi')
                ->icon(Heroicon::OutlinedPlus)
                ->action('createConnection'),
        ];
    }

    public function createConnection(): void
    {
        $state = $this->form->getState();
        $application = ClientApplication::query()->findOrFail($state['client_application_id']);
        $provisioner = app(ConnectionProvisioner::class);

        $connection = match ($state['connection_type']) {
            'managed_number' => $provisioner->createManagedNumber(
                application: $application,
                name: (string) $state['name'],
                makeDefault: true,
            ),
            'provider_route' => $provisioner->createProviderRoute(
                application: $application,
                name: (string) $state['name'],
                driver: (string) ($state['driver'] ?? 'fonnte'),
                configuration: is_array($state['configuration'] ?? null) ? $state['configuration'] : [],
                makeDefault: true,
            ),
            default => null,
        };

        if ($connection === null) {
            Notification::make()->title('Tipe koneksi tidak valid')->danger()->send();

            return;
        }

        Notification::make()
            ->title('Koneksi dibuat')
            ->body($connection->isManagedNumber()
                ? 'Lanjutkan dengan scan QR atau pairing dari halaman detail koneksi.'
                : 'Validasi provider dari halaman detail koneksi, lalu kirim pesan uji.')
            ->success()
            ->send();

        $this->redirect(ViewWhatsAppConnection::getUrl(['record' => $connection]));
    }
}
