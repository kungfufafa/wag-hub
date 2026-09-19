<?php

namespace App\Filament\Resources\WhatsAppConnections\Pages;

use App\Domain\Connection\ConnectionStatus;
use App\Exceptions\ConnectionException;
use App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource;
use App\Models\ClientApplication;
use App\Models\WhatsAppConnection;
use App\Services\Connection\ConnectionMessageSender;
use App\Services\Connection\ConnectionProvisioner;
use App\Services\Connection\ConnectionSetupPresenter;
use App\Services\Connection\ConnectionStatusResolver;
use App\Services\IntegrationPack;
use App\Support\PayloadHasher;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

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

    public int $wizardStep = 1;

    public ?int $connectionRecordId = null;

    public ?string $qr = null;

    public ?string $pairingCode = null;

    public string $testRecipient = '';

    public string $testText = 'WAG Hub test message';

    public string $integrationEnv = '';

    public function mount(): void
    {
        $this->form->fill([
            'client_application_id' => ClientApplication::query()->orderBy('name')->value('id'),
            'connection_type' => 'managed_number',
            'name' => 'WhatsApp Utama',
            'setup_mode' => 'qr',
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
        return [];
    }

    public function connection(): ?WhatsAppConnection
    {
        if ($this->connectionRecordId === null) {
            return null;
        }

        return WhatsAppConnection::query()->find($this->connectionRecordId);
    }

    public function createConnection(): void
    {
        $state = $this->form->getState();
        $application = ClientApplication::query()->findOrFail($state['client_application_id']);
        $provisioner = app(ConnectionProvisioner::class);

        try {
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
        } catch (ConnectionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if ($connection === null) {
            Notification::make()->title('Tipe koneksi tidak valid')->danger()->send();

            return;
        }

        $this->connectionRecordId = (int) $connection->getKey();
        $this->wizardStep = 2;

        if ($connection->isManagedNumber()) {
            $this->startSetup((string) ($state['setup_mode'] ?? 'qr'));
        } else {
            $this->validateProvider();
        }
    }

    public function startSetup(string $mode = 'qr'): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        try {
            app(ConnectionProvisioner::class)->startManagedSetup($connection, $mode);
        } catch (ConnectionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }

        $this->refreshSetupState();
    }

    public function validateProvider(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        try {
            app(ConnectionProvisioner::class)->validateProviderRoute($connection);
            Notification::make()->title('Provider divalidasi')->success()->send();
        } catch (ConnectionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }

        $this->refreshSetupState();
    }

    public function poll(): void
    {
        $this->refreshSetupState();

        $connection = $this->connection();

        if ($connection !== null && $connection->connectionStatus()->canSend() && $this->wizardStep === 2) {
            $this->wizardStep = 3;
        }
    }

    public function refreshSetupState(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        $connection = app(ConnectionStatusResolver::class)->refresh($connection);
        $setup = app(ConnectionSetupPresenter::class)->setupPayload($connection);
        $this->qr = is_string($setup['qr'] ?? null) ? $setup['qr'] : null;
        $this->pairingCode = is_string($setup['pairing_code'] ?? null) ? $setup['pairing_code'] : null;
    }

    public function sendTestMessage(): void
    {
        $connection = $this->connection();

        if ($connection === null || trim($this->testRecipient) === '') {
            Notification::make()->title('Isi nomor penerima uji')->warning()->send();

            return;
        }

        $application = $connection->clientApplication;
        $payload = [
            'recipient' => ['type' => 'phone', 'value' => trim($this->testRecipient)],
            'message' => ['type' => 'text', 'text' => $this->testText],
            'purpose' => 'notification',
            'mode' => 'sync',
            'metadata' => ['test' => true, 'source' => 'connect-wizard'],
        ];

        try {
            $message = app(ConnectionMessageSender::class)->send(
                application: $application,
                payload: $payload,
                idempotencyKey: 'wizard-test:'.(string) Str::uuid(),
                payloadHash: app(PayloadHasher::class)->hash($payload, (string) config('app.key')),
                correlationId: (string) Str::uuid(),
                connection: $connection,
            );

            if ((string) $message->status === 'provider_accepted') {
                Notification::make()->title('Pesan uji terkirim')->success()->send();
                $this->prepareIntegrationSnippet();
                $this->wizardStep = 4;
            } else {
                Notification::make()->title('Pengiriman uji belum berhasil')->warning()->send();
            }
        } catch (ConnectionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }

        $this->refreshSetupState();
    }

    public function skipToIntegration(): void
    {
        $this->prepareIntegrationSnippet();
        $this->wizardStep = 4;
    }

    public function shouldPoll(): bool
    {
        return $this->wizardStep === 2
            && $this->connection()?->isManagedNumber() === true
            && ! in_array($this->connection()?->status, [ConnectionStatus::Ready->value, ConnectionStatus::Degraded->value], true);
    }

    private function prepareIntegrationSnippet(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        $pack = app(IntegrationPack::class);
        $this->integrationEnv = implode("\n", [
            'WAG_URL='.$pack->hubUrl(),
            'WAG_TOKEN=<token-aplikasi-dari-tab-kredensial>',
            'WAG_CONNECTION_ID='.(string) $connection->uuid,
        ]);
    }
}
