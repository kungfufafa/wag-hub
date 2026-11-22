<?php

namespace App\Filament\Pages;

use App\Domain\Connections\ConnectionType;
use App\Exceptions\WhatsAppConnectionException;
use App\Exceptions\WhatsAppEngineException;
use App\Filament\Support\CopiesToClipboard;
use App\Filament\Support\PanelNavigation;
use App\Models\ClientApplication;
use App\Models\WhatsAppConnection;
use App\Services\Connections\ConnectionHealthProjector;
use App\Services\Connections\ConnectionPresenter;
use App\Services\Connections\ConnectionProvisioner;
use App\Services\Connections\ConnectionTestSender;
use App\Services\IntegrationPack;
use App\Support\PhoneNormalizer;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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

    /**
     * Idempotency key of the current setup attempt: a double click or a retry after a failure
     * resumes the same connection and v2 session instead of creating another one.
     */
    public string $attemptKey = '';

    public function mount(): void
    {
        $this->attemptKey = (string) Str::uuid();

        $requested = request()->query('application');
        $application = is_string($requested)
            ? $this->findRequestedApplication($requested)
            : $this->applications()->first();

        $this->applicationId = $application?->getKey();
    }

    private function findRequestedApplication(string $requested): ?ClientApplication
    {
        if (Str::isUuid($requested)) {
            return ClientApplication::query()->where('uuid', $requested)->first();
        }

        if (ctype_digit($requested)) {
            return ClientApplication::query()->whereKey((int) $requested)->first();
        }

        return null;
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

        if ($connection === null) {
            return null;
        }

        $presentation = app(ConnectionPresenter::class)->toArray($connection, true);
        if ($connection->isManaged() && $presentation['status'] === 'connecting'
            && ($connection->setup_state['auth_method'] ?? null) === 'pairing') {
            $presentation['recommended_action'] = 'Enter pairing code';
        }
        $presentation['status_label'] = $connection->statusEnum()->label();
        $presentation['recommended_action'] = match ($presentation['recommended_action']) {
            'Enter pairing code' => 'Masukkan kode pairing di WhatsApp pada HP Anda.',
            'Scan QR' => 'Pindai kode QR melalui menu Perangkat tertaut di WhatsApp.',
            'Fix provider settings', 'Fix credentials' => 'Perbaiki pengaturan provider.',
            'Reconnect' => 'Hubungkan kembali WhatsApp.',
            'Start WAG Hub runner' => 'Jalankan layanan WAG Hub runner.',
            'Provider temporarily unavailable' => 'Provider sementara tidak tersedia.',
            'Send a test message' => 'Kirim pesan uji untuk memeriksa koneksi.',
            default => 'Menunggu kesiapan koneksi.',
        };

        return $presentation;
    }

    public function createConnection(): void
    {
        $application = ClientApplication::query()->find($this->applicationId);

        if ($application === null) {
            Notification::make()->title('Pilih aplikasi dulu')->danger()->send();

            return;
        }

        try {
            $this->validateSettings();
        } catch (ValidationException $exception) {
            Notification::make()
                ->title('Periksa pengaturan koneksi')
                ->body(collect($exception->errors())->flatten()->first())
                ->danger()
                ->send();

            throw $exception;
        }

        try {
            $existing = $this->connection();
            if ($existing !== null && ! $existing->isManaged()) {
                app(ConnectionProvisioner::class)->retry($existing, $this->provisioningPayload()['provider']['configuration']);
                $this->step = 3;
                Notification::make()->title('Pengaturan koneksi diperbarui')->success()->send();

                return;
            }

            $provisioned = app(ConnectionProvisioner::class)->provision($application, $this->provisioningPayload(), $this->creationKey());
            $this->connectionUuid = $provisioned->connection->uuid;
            $this->step = 3;
            Notification::make()->title('Koneksi disiapkan')->success()->send();
        } catch (WhatsAppEngineException $exception) {
            // v2 session failures (capacity_exceeded, idempotency_conflict) create nothing.
            Notification::make()
                ->title(match ($exception->errorCode) {
                    'capacity_exceeded' => 'Kuota nomor WhatsApp aplikasi ini sudah penuh',
                    'idempotency_conflict' => 'Pengaturan berubah: mulai percobaan baru',
                    default => 'Koneksi belum bisa disiapkan',
                })
                ->body($this->exceptionMessage($exception))
                ->danger()
                ->send();

            if ($exception->errorCode === 'idempotency_conflict') {
                $this->attemptKey = (string) Str::uuid();
            }
        } catch (Throwable $exception) {
            // A provider route that failed validation is kept so the admin can fix and retry it.
            $connection = WhatsAppConnection::query()
                ->where('client_application_id', $application->getKey())
                ->where('creation_key', $this->creationKey())
                ->first();
            $this->connectionUuid = $connection?->uuid;
            $this->step = $connection === null ? $this->step : 3;
            Notification::make()->title('Koneksi perlu ditindaklanjuti')->body($this->exceptionMessage($exception))->warning()->send();
        }
    }

    public function startNewAttempt(): void
    {
        $this->resetValidation();
        $this->attemptKey = (string) Str::uuid();
        $this->connectionUuid = null;
        $this->mode = 'qr';
        $this->step = 2;
    }

    public function updatedType(): void
    {
        $this->mode = 'qr';
        $this->resetValidation();
    }

    public function editSettings(): void
    {
        if ($this->connection()?->isManaged() === false) {
            $this->step = 2;
        }
    }

    private function validateSettings(): void
    {
        $rules = [
            'type' => 'required|in:managed_number,provider_route',
        ];
        if ($this->type === 'managed_number') {
            $rules['mode'] = 'required|in:qr,pairing';
        }
        if ($this->type === 'managed_number' && $this->mode === 'pairing') {
            $this->phone = trim($this->phone);
            $rules['phone'] = ['required', 'regex:/^\+?[0-9]{8,15}$/'];
        }
        if ($this->type === 'provider_route') {
            $rules['driver'] = 'required|in:fonnte,waha,gowa,waba';
            $rules += match ($this->driver) {
                'fonnte' => ['token' => 'required'],
                'waha' => ['baseUrl' => 'required|url', 'session' => 'required', 'token' => 'required'],
                'gowa' => ['baseUrl' => 'required|url', 'username' => 'required', 'password' => 'required'],
                'waba' => ['phoneNumberId' => 'required', 'accessToken' => 'required'],
                default => [],
            };
        }
        $existing = $this->connection();
        if ($existing !== null) {
            $rules['type'] = ['required', Rule::in([$existing->type])];
            if (! $existing->isManaged()) {
                $rules['driver'] = ['required', Rule::in([
                    $existing->providerAccount?->driver ?? ($existing->setup_state['driver'] ?? null),
                ])];
            }
        }
        $this->validate($rules, [
            'required' => ':attribute wajib diisi.',
            'in' => 'Pilihan :attribute tidak valid.',
            'url' => ':attribute harus berupa URL yang valid.',
            'phone.regex' => 'Nomor HP harus terdiri dari 8–15 digit dengan tanda + opsional di awal.',
        ], [
            'type' => 'Jenis koneksi', 'mode' => 'Mode koneksi', 'driver' => 'Provider',
            'phone' => 'Nomor HP', 'token' => 'Token / kunci API', 'baseUrl' => 'URL dasar',
            'session' => 'Sesi', 'username' => 'Nama pengguna', 'password' => 'Kata sandi',
            'phoneNumberId' => 'ID nomor telepon', 'accessToken' => 'Token akses',
        ]);
        if (isset($rules['phone'])) {
            $this->phone = PhoneNormalizer::international($this->phone);
        }
    }

    private function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof InvalidArgumentException && in_array($exception->getMessage(), [
            'Nomor WhatsApp tidak valid.',
            'Kode negara WhatsApp tidak didukung.',
            'Panjang nomor WhatsApp tidak valid.',
        ], true)) {
            return $exception->getMessage();
        }

        $code = $exception instanceof WhatsAppConnectionException || $exception instanceof WhatsAppEngineException
            ? $exception->errorCode
            : null;

        return match ($code) {
            'capacity_exceeded' => 'Kuota nomor WhatsApp aplikasi ini sudah penuh. Hapus nomor yang tidak digunakan atau hubungi administrator.',
            'idempotency_conflict' => 'Pengaturan berbeda dari percobaan sebelumnya. Mulai percobaan baru melalui Koneksi lain.',
            'connection_removed', 'session_removed', 'session_not_found' => 'Koneksi atau sesi sudah dihapus. Mulai percobaan baru melalui Koneksi lain.',
            'provider_configuration_invalid' => 'Pengaturan provider belum lengkap atau tidak valid. Pilih Ubah pengaturan untuk memperbaikinya.',
            'provider_endpoint_not_allowed' => 'URL provider tidak diizinkan. Gunakan alamat HTTPS publik yang diizinkan.',
            'invalid_phone', 'recipient_invalid' => 'Nomor WhatsApp tidak valid.',
            'invalid_message' => 'Pesan tidak valid. Isi teks pesan sebelum mengirim.',
            'connection_not_ready', 'session_not_working' => 'Koneksi WhatsApp belum siap. Hubungkan kembali sebelum mengirim pesan.',
            'operation_pending', 'operation_incomplete' => 'Proses koneksi masih berlangsung. Tunggu sebentar lalu coba lagi.',
            'engine_unavailable', 'engine_unconfigured', 'engine_unreachable' => 'Layanan WhatsApp belum tersedia. Periksa WAG Hub runner lalu coba lagi.',
            'capability_not_supported' => 'Operasi ini tidak didukung oleh koneksi. Periksa pengaturan provider atau hubungi administrator.',
            default => 'Periksa pengaturan koneksi dan ketersediaan layanan, lalu coba lagi.',
        };
    }

    private function creationKey(): string
    {
        return 'panel:'.$this->attemptKey;
    }

    public function retrySetup(): void
    {
        $connection = $this->connection();

        if ($connection === null) {
            return;
        }

        try {
            $connection = $connection->isManaged()
                ? app(ConnectionProvisioner::class)->connect($connection, $this->managedStartInput())
                : app(ConnectionProvisioner::class)->retry(
                    $connection,
                    $this->provisioningPayload()['provider']['configuration'] ?? [],
                );
            $this->connectionUuid = $connection->uuid;
            Notification::make()->title('Setup dilanjutkan')->success()->send();
        } catch (WhatsAppEngineException $exception) {
            Notification::make()
                ->title($exception->errorCode === 'capacity_exceeded'
                    ? 'Kuota nomor WhatsApp aplikasi ini sudah penuh'
                    : 'Setup belum selesai')
                ->body($this->exceptionMessage($exception))
                ->danger()
                ->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Setup belum selesai')->body($this->exceptionMessage($exception))->warning()->send();
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
            $delivery = app(ConnectionTestSender::class)->send($connection, $this->testRecipient, $this->testText);
            Notification::make()
                ->title($delivery->accepted() ? 'Uji kirim diterima' : 'Uji kirim gagal')
                ->body($delivery->accepted()
                    ? 'Pesan diterima.'
                    : (string) ($delivery->message ?? 'Provider menolak atau gagal menerima pesan.'))
                ->{$delivery->accepted() ? 'success' : 'danger'}()
                ->send();

            if ($delivery->accepted()) {
                $this->step = 5;
            }
        } catch (Throwable $exception) {
            Notification::make()->title('Uji kirim gagal')->body($this->exceptionMessage($exception))->danger()->send();
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
     * @return array{auth_method: string, phone: string|null}
     */
    private function managedStartInput(): array
    {
        $pairing = $this->mode === 'pairing';

        return [
            'auth_method' => $pairing ? 'pairing' : 'qr',
            'phone' => $pairing && trim($this->phone) !== '' ? trim($this->phone) : null,
        ];
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
        ];

        if ($this->type === ConnectionType::ManagedNumber->value) {
            $payload += $this->managedStartInput();
        }

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
