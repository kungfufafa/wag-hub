<?php

namespace App\Filament\Pages;

use App\Domain\Connections\ConnectionType;
use App\Filament\Support\CopiesToClipboard;
use App\Filament\Support\PanelNavigation;
use App\Models\ClientApplication;
use App\Models\WhatsAppConnection;
use App\Services\Connections\ConnectionHealthProjector;
use App\Services\Connections\ConnectionPresenter;
use App\Services\Connections\ConnectionProvisioner;
use App\Services\Connections\ConnectionTestSender;
use App\Services\IntegrationPack;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Throwable;
use UnitEnum;

class ConnectWhatsApp extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPlusCircle;

    protected static ?string $navigationLabel = 'Hubungkan WhatsApp';

    protected static ?string $title = 'Hubungkan WhatsApp';

    protected static ?string $slug = 'hubungkan-whatsapp';

    protected static string|UnitEnum|null $navigationGroup = PanelNavigation::WHATSAPP;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.connect-whatsapp';

    protected Width|string|null $maxContentWidth = Width::Full;

    public int $step = 1;

    public ?int $applicationId = null;

    public string $type = 'managed_number';

    public string $name = 'WhatsApp';

    public string $mode = 'qr';

    public string $phone = '';

    public string $driver = 'fonnte';

    public string $token = '';

    public string $baseUrl = '';

    public string $session = 'default';

    public string $username = '';

    public string $password = '';

    public string $phoneNumberId = '';

    public string $accessToken = '';

    public ?string $connectionUuid = null;

    public string $testRecipient = '';

    public string $testText = 'Tes koneksi WAG Hub';

    public ?string $issuedEnv = null;

    public function mount(): void
    {
        $requested = request()->query('application');
        $application = is_string($requested)
            ? ClientApplication::query()->where('uuid', $requested)->orWhere('id', $requested)->first()
            : $this->applications()->first();

        $this->applicationId = $application?->getKey();
    }

    /**
     * @return Collection<int, ClientApplication>
     */
    public function applications(): Collection
    {
        return ClientApplication::query()->where('is_active', true)->orderBy('name')->get();
    }

    public function connection(): ?WhatsAppConnection
    {
        if ($this->connectionUuid === null) {
            return null;
        }

        $connection = WhatsAppConnection::query()->where('uuid', $this->connectionUuid)->first();

        return $connection === null
            ? null
            : app(ConnectionHealthProjector::class)->hydrate($connection);
    }

    public function canSendTest(): bool
    {
        return $this->connection()?->canAttemptSend() ?? false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function presentation(): ?array
    {
        $connection = $this->connection();

        return $connection === null ? null : app(ConnectionPresenter::class)->toArray($connection, true);
    }

    public function createConnection(): void
    {
        $application = ClientApplication::query()->find($this->applicationId);

        if ($application === null) {
            Notification::make()->title('Pilih aplikasi dulu')->danger()->send();

            return;
        }

        if ($this->type === ConnectionType::ManagedNumber->value
            && $this->mode === 'pairing'
            && trim($this->phone) === '') {
            Notification::make()->title('Nomor HP pairing wajib diisi')->danger()->send();

            return;
        }

        try {
            $provisioned = app(ConnectionProvisioner::class)->provision($application, $this->provisioningPayload());
            $this->connectionUuid = $provisioned->connection->uuid;
            $this->step = 3;
            Notification::make()->title('Koneksi disiapkan')->success()->send();
        } catch (Throwable $exception) {
            $connection = WhatsAppConnection::query()
                ->where('client_application_id', $application->getKey())
                ->where('name', $this->name)
                ->first();
            $this->connectionUuid = $connection?->uuid;
            $this->step = 3;
            Notification::make()->title('Koneksi perlu ditindaklanjuti')->body($exception->getMessage())->warning()->send();
        }
    }

    public function retrySetup(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        try {
            $connection = app(ConnectionProvisioner::class)->retry($connection);
            $this->connectionUuid = $connection->uuid;
            Notification::make()->title('Setup dilanjutkan')->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Setup belum selesai')->body($exception->getMessage())->warning()->send();
        }
    }

    public function sendTest(): void
    {
        $connection = $this->connection();

        if ($connection === null || ! $connection->canAttemptSend()) {
            Notification::make()->title('Koneksi belum siap untuk uji kirim')->danger()->send();

            return;
        }

        try {
            $response = app(ConnectionTestSender::class)->send($connection, $this->testRecipient, $this->testText);
            $payload = $response->getData(true);
            $success = ($payload['data']['status'] ?? null) === 'provider_accepted';
            Notification::make()
                ->title($success ? 'Uji kirim berhasil' : 'Uji kirim gagal')
                ->body($success
                    ? 'Provider menerima pesan melalui POST /api/v1/messages.'
                    : (string) ($payload['message'] ?? 'Provider menolak atau gagal menerima pesan.'))
                ->{$success ? 'success' : 'danger'}()
                ->send();

            if ($success) {
                $this->step = 5;
            }
        } catch (Throwable $exception) {
            Notification::make()->title('Uji kirim gagal')->body($exception->getMessage())->danger()->send();
        }
    }

    public function issueIntegration(): void
    {
        $application = ClientApplication::query()->find($this->applicationId);

        if ($application === null) {
            return;
        }

        $pack = app(IntegrationPack::class)->copyOrIssue($application);
        $this->issuedEnv = $pack['env'];
        $this->step = 5;
    }

    public function envCopyHandler(): string
    {
        return CopiesToClipboard::alpine($this->issuedEnv ?? '', 'Konfigurasi integrasi disalin.');
    }

    /**
     * @return array<string, mixed>
     */
    private function provisioningPayload(): array
    {
        $payload = [
            'name' => $this->name !== '' ? $this->name : 'WhatsApp',
            'type' => $this->type,
            'is_default' => false,
            'mode' => $this->mode,
            'phone' => $this->phone !== '' ? $this->phone : null,
        ];

        if ($this->type === ConnectionType::ProviderRoute->value) {
            $payload['provider'] = [
                'driver' => $this->driver,
                'configuration' => match ($this->driver) {
                    'waha' => [
                        'base_url' => $this->baseUrl,
                        'session' => $this->session,
                        'api_key' => $this->token,
                    ],
                    'fonnte' => ['token' => $this->token],
                    'gowa' => [
                        'base_url' => $this->baseUrl,
                        'username' => $this->username,
                        'password' => $this->password,
                    ],
                    'waba' => [
                        'phone_number_id' => $this->phoneNumberId,
                        'access_token' => $this->accessToken,
                    ],
                    default => [],
                },
            ];
        }

        return $payload;
    }
}
