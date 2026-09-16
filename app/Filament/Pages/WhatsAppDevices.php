<?php

namespace App\Filament\Pages;

use App\Domain\WhatsApp\SessionStatus;
use App\Models\ProviderAccount;
use App\Services\WhatsAppSessionManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\View as SchemaView;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use UnitEnum;

/**
 * "Perangkat WhatsApp" — WhatsApp-Web-style linked-devices screen for our own
 * self-hosted engine (WAHA/Baileys NOWEB): scan a QR to pair a number, watch
 * live status, then chat through the shared Inbox.
 */
class WhatsAppDevices extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDevicePhoneMobile;

    protected static string|UnitEnum|null $navigationGroup = 'Konfigurasi';

    protected static ?int $navigationSort = 25;

    protected static ?string $navigationLabel = 'Perangkat WhatsApp';

    protected static ?string $title = 'Perangkat WhatsApp';

    protected static ?string $slug = 'devices';

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?int $deviceId = null;

    public ?string $qr = null;

    public function mount(): void
    {
        $first = $this->devices()->first();

        if ($first instanceof ProviderAccount) {
            $this->deviceId = $first->id;
            $this->refreshStatus();
        }
    }

    /**
     * @return Collection<int, ProviderAccount>
     */
    public function devices(): Collection
    {
        return ProviderAccount::query()
            ->where('driver', 'waha')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function selected(): ?ProviderAccount
    {
        if ($this->deviceId === null) {
            return null;
        }

        return $this->devices()->firstWhere('id', $this->deviceId);
    }

    public function selectDevice(int $id): void
    {
        $this->deviceId = $id;
        $this->qr = null;
        $this->refreshStatus();
    }

    public function statusFor(ProviderAccount $account): SessionStatus
    {
        return $account->sessionStatus();
    }

    public function connectedNumber(ProviderAccount $account): ?string
    {
        $meta = is_array($account->session_meta) ? $account->session_meta : [];
        $phone = $meta['phone'] ?? null;

        if (! is_string($phone) || $phone === '') {
            return null;
        }

        // Turn a raw JID (628...@c.us) into a friendly +62... display.
        $digits = preg_replace('/\D+/', '', explode('@', $phone)[0]) ?? '';

        return $digits !== '' ? '+'.$digits : $phone;
    }

    public function connect(): void
    {
        $this->run(fn (ProviderAccount $account, WhatsAppSessionManager $sessions) => $sessions->connect($account), 'Menghubungkan perangkat…');
        $this->refreshStatus();
    }

    public function disconnect(): void
    {
        $this->run(fn (ProviderAccount $account, WhatsAppSessionManager $sessions) => $sessions->disconnect($account), 'Perangkat diputus.');
        $this->qr = null;
    }

    public function restart(): void
    {
        $this->run(fn (ProviderAccount $account, WhatsAppSessionManager $sessions) => $sessions->restart($account), 'Sesi dimulai ulang.');
        $this->refreshStatus();
    }

    /**
     * Polled from the view while pairing: keep status + QR fresh.
     */
    public function poll(): void
    {
        if ($this->selected() === null) {
            return;
        }

        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $account = $this->selected();

        if ($account === null) {
            $this->qr = null;

            return;
        }

        try {
            $status = app(WhatsAppSessionManager::class)->refresh($account);
        } catch (InvalidArgumentException) {
            $this->qr = null;

            return;
        }

        $this->qr = $status->needsQr()
            ? app(WhatsAppSessionManager::class)->qrDataUri($account)
            : null;
    }

    /**
     * @param  callable(ProviderAccount, WhatsAppSessionManager): SessionStatus  $callback
     */
    private function run(callable $callback, string $successMessage): void
    {
        $account = $this->selected();

        if ($account === null) {
            Notification::make()->title('Pilih perangkat dulu.')->danger()->send();

            return;
        }

        try {
            $callback($account, app(WhatsAppSessionManager::class));
            Notification::make()->title($successMessage)->success()->send();
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title('Gagal')->body($exception->getMessage())->danger()->send();
        }
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.pages.whatsapp-devices')->columnSpanFull(),
        ]);
    }

    public function getSubheading(): ?string
    {
        return 'Sambungkan nomor WhatsApp Anda sendiri lewat engine self-hosted (WAHA/Baileys), lalu balas dari Inbox.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Segarkan status')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => $this->selected() === null)
                ->action(fn () => $this->refreshStatus()),
        ];
    }
}
