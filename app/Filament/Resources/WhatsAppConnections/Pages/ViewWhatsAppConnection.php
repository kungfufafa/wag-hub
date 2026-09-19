<?php

namespace App\Filament\Resources\WhatsAppConnections\Pages;

use App\Domain\Connection\ConnectionStatus;
use App\Domain\WhatsApp\SessionStatus;
use App\Exceptions\ConnectionException;
use App\Filament\Resources\WhatsAppConnections\WhatsAppConnectionResource;
use App\Models\WhatsAppConnection;
use App\Services\Connection\ConnectionFallbackManager;
use App\Services\Connection\ConnectionMessageSender;
use App\Services\Connection\ConnectionProvisioner;
use App\Services\Connection\ConnectionSetupPresenter;
use App\Services\Connection\ConnectionStatusResolver;
use App\Services\IntegrationPack;
use App\Support\PayloadHasher;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Str;

class ViewWhatsAppConnection extends ViewRecord
{
    protected static string $resource = WhatsAppConnectionResource::class;

    protected string $view = 'filament.pages.manage-connection';

    public ?string $qr = null;

    public ?string $pairingCode = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->refreshSetupState();
    }

    public function getRecord(): WhatsAppConnection
    {
        /** @var WhatsAppConnection */
        return parent::getRecord();
    }

    public function poll(): void
    {
        $this->refreshSetupState();
    }

    public function refreshSetupState(): void
    {
        $connection = app(ConnectionStatusResolver::class)->refresh($this->getRecord());
        $this->record = $connection;

        $setup = app(ConnectionSetupPresenter::class)->setupPayload($connection);
        $this->qr = is_string($setup['qr'] ?? null) ? $setup['qr'] : null;
        $this->pairingCode = is_string($setup['pairing_code'] ?? null) ? $setup['pairing_code'] : null;
    }

    public function startQrSetup(): void
    {
        $this->runSetup('qr');
    }

    public function startPairingSetup(?string $phone = null): void
    {
        $this->runSetup('pairing', $phone);
    }

    public function validateProvider(): void
    {
        try {
            app(ConnectionProvisioner::class)->validateProviderRoute($this->getRecord());
            Notification::make()->title('Provider divalidasi')->success()->send();
        } catch (ConnectionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }

        $this->refreshSetupState();
    }

    /**
     * @return array<string, mixed>
     */
    public function presented(): array
    {
        return WhatsAppConnectionResource::presentRecord($this->getRecord());
    }

    public function shouldPoll(): bool
    {
        $connection = $this->getRecord();

        if (! $connection->isManagedNumber()) {
            return false;
        }

        return in_array($connection->status, [
            ConnectionStatus::SetupRequired->value,
            ConnectionStatus::Connecting->value,
        ], true);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setDefault')
                ->label('Jadikan default')
                ->icon(Heroicon::Star)
                ->visible(fn (): bool => ! $this->getRecord()->is_default)
                ->action(function (): void {
                    app(ConnectionProvisioner::class)->setDefault($this->getRecord());
                    $this->refreshSetupState();
                    Notification::make()->title('Koneksi default diperbarui')->success()->send();
                }),
            Action::make('testMessage')
                ->label('Kirim pesan uji')
                ->icon(Heroicon::PaperAirplane)
                ->visible(fn (): bool => $this->getRecord()->connectionStatus()->canSend())
                ->form([
                    TextInput::make('recipient')
                        ->label('Nomor penerima')
                        ->placeholder('6281234567890')
                        ->required(),
                    TextInput::make('text')
                        ->label('Pesan')
                        ->default('WAG Hub test message')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->sendTestMessage((string) $data['recipient'], (string) $data['text']);
                }),
            Action::make('integration')
                ->label('Salin integrasi')
                ->icon(Heroicon::ClipboardDocumentList)
                ->visible(fn (): bool => $this->getRecord()->connectionStatus()->canSend())
                ->modalHeading('Konfigurasi integrasi')
                ->schema([
                    Textarea::make('env')
                        ->label('Environment')
                        ->rows(5)
                        ->readOnly()
                        ->default(fn (): string => $this->integrationSnippet())
                        ->extraInputAttributes(['class' => 'font-mono text-xs']),
                    Textarea::make('curl')
                        ->label('Contoh curl')
                        ->rows(6)
                        ->readOnly()
                        ->default(fn (): string => $this->curlExample())
                        ->extraInputAttributes(['class' => 'font-mono text-xs']),
                ])
                ->modalSubmitAction(false),
            Action::make('addFallback')
                ->label('Tambah fallback')
                ->icon(Heroicon::ArrowDownCircle)
                ->visible(fn (): bool => $this->getRecord()->isProviderRoute())
                ->form([
                    Select::make('provider_account_id')
                        ->label('Provider fallback')
                        ->options(fn (): array => \App\Models\ProviderAccount::query()
                            ->where('is_active', true)
                            ->whereDoesntHave('routingSteps', fn ($q) => $q->where('routing_policy_id', $this->getRecord()->routing_policy_id))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required()
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    $provider = \App\Models\ProviderAccount::query()->findOrFail($data['provider_account_id']);
                    app(ConnectionFallbackManager::class)->addFallback($this->getRecord(), $provider);
                    $this->refreshSetupState();
                    Notification::make()->title('Fallback ditambahkan')->success()->send();
                }),
        ];
    }

    private function runSetup(string $mode, ?string $phone = null): void
    {
        try {
            app(ConnectionProvisioner::class)->startManagedSetup($this->getRecord(), $mode, $phone);
            Notification::make()
                ->title($mode === 'qr' ? 'QR siap discan' : 'Kode pairing dibuat')
                ->success()
                ->send();
        } catch (ConnectionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }

        $this->refreshSetupState();
    }

    private function sendTestMessage(string $recipient, string $text): void
    {
        $connection = $this->getRecord();
        $application = $connection->clientApplication;
        $payload = [
            'recipient' => ['type' => 'phone', 'value' => $recipient],
            'message' => ['type' => 'text', 'text' => $text],
            'purpose' => 'notification',
            'mode' => 'sync',
            'metadata' => ['test' => true, 'source' => 'filament'],
        ];
        $idempotencyKey = 'panel-test:'.(string) Str::uuid();
        $payloadHash = app(PayloadHasher::class)->hash($payload, (string) config('app.key'));

        try {
            $message = app(ConnectionMessageSender::class)->send(
                application: $application,
                payload: $payload,
                idempotencyKey: $idempotencyKey,
                payloadHash: $payloadHash,
                correlationId: (string) Str::uuid(),
                connection: $connection,
            );

            if ((string) $message->status === 'provider_accepted') {
                Notification::make()->title('Pesan uji terkirim')->success()->send();
            } else {
                Notification::make()->title('Pengiriman uji belum berhasil')->body((string) $message->last_error_code)->warning()->send();
            }
        } catch (ConnectionException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();
        }

        $this->refreshSetupState();
    }

    private function integrationSnippet(): string
    {
        $pack = app(IntegrationPack::class);

        return implode("\n", [
            'WAG_URL='.$pack->hubUrl(),
            'WAG_TOKEN=<token-aplikasi>',
            'WAG_CONNECTION_ID='.(string) $this->getRecord()->uuid,
        ]);
    }

    private function curlExample(): string
    {
        $pack = app(IntegrationPack::class);
        $baseUrl = $pack->hubUrl();
        $connectionId = (string) $this->getRecord()->uuid;

        return <<<CURL
curl -X POST {$baseUrl}/api/v1/messages \\
  -H "Authorization: Bearer \$WAG_TOKEN" \\
  -H "Idempotency-Key: test-1" \\
  -H "Content-Type: application/json" \\
  -d '{"connection_id":"{$connectionId}","recipient":{"type":"phone","value":"6281234567890"},"message":{"type":"text","text":"Hello"},"purpose":"notification","mode":"sync"}'
CURL;
    }

    public function sessionStatusLabel(): string
    {
        $provider = $this->getRecord()->providerAccount;

        if ($provider === null) {
            return 'Belum disiapkan';
        }

        return $provider->sessionStatus()->label();
    }

    public function isConnected(): bool
    {
        $provider = $this->getRecord()->providerAccount;

        return $provider?->sessionStatus() === SessionStatus::Working;
    }
}
