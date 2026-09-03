<?php

namespace App\Filament\Pages;

use App\Models\ProviderAccount;
use App\Services\WhatsAppInbox as Inbox;
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

class WhatsAppInbox extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Operasi';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Inbox WhatsApp';

    protected static ?string $title = 'Inbox WhatsApp';

    protected static ?string $slug = 'inbox';

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?int $providerId = null;

    public ?string $chatId = null;

    public string $chatTitle = '';

    public string $search = '';

    public string $draft = '';

    public string $newRecipient = '';

    public bool $composingNew = false;

    /**
     * @var list<array{id: string, title: string, preview: string, timestamp: ?string, is_group: bool, last_from_me?: ?bool, provider_id?: ?int, provider_name?: ?string, driver?: ?string}>
     */
    public array $chats = [];

    /**
     * @var list<array{id: string, body: string, from_me: bool, timestamp: ?string, kind: string}>
     */
    public array $messages = [];

    public ?string $status = null;

    public function mount(): void
    {
        $requested = (int) request()->query('provider');
        $channels = $this->channels();

        if ($requested > 0 && $channels->contains(fn (ProviderAccount $account): bool => $account->id === $requested)) {
            $this->providerId = $requested;
        } else {
            $this->providerId = $channels->first()?->id;
        }

        $this->loadChats();
    }

    public function updatedSearch(): void
    {
        $this->loadChats();
    }

    public function selectChat(string $chatId, string $title = '', ?int $providerId = null): void
    {
        $this->providerId = $this->resolveProviderId($chatId, $providerId);
        $this->composingNew = false;
        $this->chatId = $chatId;
        $this->chatTitle = $title !== '' ? $title : $chatId;
        $this->status = null;
        $this->loadMessages();
    }

    public function startNewChat(): void
    {
        $this->composingNew = true;
        $this->chatId = null;
        $this->chatTitle = 'Obrolan baru';
        $this->messages = [];
        $this->newRecipient = '';
        $this->draft = '';
        $this->status = null;
        $this->providerId ??= $this->channels()->first()?->id;
    }

    public function refreshInbox(): void
    {
        $this->loadChats();

        if (filled($this->chatId)) {
            $this->loadMessages();
        }
    }

    public function send(): void
    {
        $account = $this->selectedAccount();

        if ($account === null) {
            Notification::make()->title('Pilih akun wrapping dulu.')->danger()->send();

            return;
        }

        $target = $this->composingNew ? $this->newRecipient : (string) $this->chatId;
        $body = trim($this->draft);

        if ($target === '' || $body === '') {
            Notification::make()->title('Isi nomor tujuan dan pesan.')->danger()->send();

            return;
        }

        try {
            $dispatch = app(Inbox::class)->send($account, $target, $body);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if (! $dispatch->isAccepted()) {
            Notification::make()
                ->title('Pesan belum terkirim')
                ->body($dispatch->result->errorMessage ?? 'Provider menolak atau tidak menjawab.')
                ->danger()
                ->send();

            return;
        }

        $this->draft = '';
        $this->composingNew = false;
        $this->chatId = $dispatch->chatId;
        $this->chatTitle = $this->chatTitle !== '' && $this->chatTitle !== 'Obrolan baru'
            ? $this->chatTitle
            : $dispatch->chatId;
        $this->status = 'Terkirim lewat '.$account->name;
        Notification::make()->title('Pesan terkirim.')->success()->send();

        $this->loadChats();
        $this->loadMessages();
    }

    /**
     * @return Collection<int, ProviderAccount>
     */
    public function channels(): Collection
    {
        return app(Inbox::class)->channels();
    }

    public function selectedAccount(): ?ProviderAccount
    {
        if ($this->providerId === null) {
            return null;
        }

        return $this->channels()->firstWhere('id', $this->providerId);
    }

    public function driverLabel(?string $driver): string
    {
        if ($driver === null || $driver === '') {
            return '';
        }

        return app(Inbox::class)->driverLabel($driver);
    }

    public function isActiveChat(array $chat): bool
    {
        return $this->chatId === ($chat['id'] ?? null)
            && $this->providerId === ($chat['provider_id'] ?? null);
    }

    public function getSubheading(): ?string
    {
        return 'Kirim dan baca chat langsung dari akun WAHA, GOWA, Fonnte, dan WABA.';
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            SchemaView::make('filament.pages.whatsapp-inbox')->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newChat')
                ->label('Obrolan baru')
                ->icon(Heroicon::OutlinedPlus)
                ->action(fn () => $this->startNewChat()),
            Action::make('refreshInbox')
                ->label('Muat ulang')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->action(fn () => $this->refreshInbox()),
        ];
    }

    private function resolveProviderId(string $chatId, ?int $providerId): ?int
    {
        if ($providerId !== null && $providerId > 0) {
            return $providerId;
        }

        $matches = collect($this->chats)->where('id', $chatId)->values();

        if ($this->providerId !== null && $matches->contains(fn (array $chat): bool => ($chat['provider_id'] ?? null) === $this->providerId)) {
            return $this->providerId;
        }

        $first = $matches->first();

        return is_array($first) ? ($first['provider_id'] ?? $this->providerId) : $this->providerId;
    }

    private function loadChats(): void
    {
        $this->chats = app(Inbox::class)->chats(filled($this->search) ? $this->search : null);
    }

    private function loadMessages(): void
    {
        $account = $this->selectedAccount();

        if ($account === null || ! filled($this->chatId)) {
            $this->messages = [];

            return;
        }

        $this->messages = app(Inbox::class)->messages($account, (string) $this->chatId);
    }
}
