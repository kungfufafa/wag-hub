<?php

namespace App\Filament\Pages;

use App\Domain\Delivery\AttachmentKind;
use App\Domain\Delivery\OutboundAttachment;
use App\Models\ProviderAccount;
use App\Services\AttachmentService;
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
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;
use UnitEnum;

class WhatsAppInbox extends Page
{
    use WithFileUploads;

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

    public ?TemporaryUploadedFile $attachmentFile = null;

    public string $attachmentUrl = '';

    public string $attachmentKind = 'document';

    public string $submissionUuid = '';

    public ?string $storedAttachmentId = null;

    public ?string $attachmentError = null;

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
        $this->resetSubmission();
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

    public function updatedAttachmentFile(): void
    {
        $this->resetSubmission();
        $this->attachmentError = null;

        if ($this->attachmentFile !== null) {
            $this->attachmentUrl = '';
        }
    }

    public function updatedAttachmentUrl(): void
    {
        $this->resetSubmission();
        $this->attachmentError = null;

        if (trim($this->attachmentUrl) !== '') {
            $this->attachmentFile = null;
        }
    }

    public function selectChat(string $chatId, string $title = '', ?int $providerId = null): void
    {
        $this->resetSubmission();
        $this->providerId = $this->resolveProviderId($chatId, $providerId);
        $this->composingNew = false;
        $this->chatId = $chatId;
        $this->chatTitle = $title !== '' ? $title : $chatId;
        $this->status = null;
        $this->loadMessages();
    }

    public function startNewChat(): void
    {
        $this->resetSubmission();
        $this->composingNew = true;
        $this->chatId = null;
        $this->chatTitle = 'Obrolan baru';
        $this->messages = [];
        $this->newRecipient = '';
        $this->draft = '';
        $this->clearAttachment();
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
        $this->attachmentError = null;

        if ($this->submissionUuid === '') {
            $this->resetSubmission();
        }

        $account = $this->selectedAccount();

        if ($account === null) {
            Notification::make()->title('Pilih akun wrapping dulu.')->danger()->send();

            return;
        }

        $target = $this->composingNew ? $this->newRecipient : (string) $this->chatId;
        $body = trim($this->draft);
        $attachment = null;

        if ($target === '') {
            Notification::make()->title('Isi nomor tujuan.')->danger()->send();

            return;
        }

        try {
            if ($this->attachmentFile !== null) {
                $stored = $this->storedAttachmentId !== null
                    ? app(AttachmentService::class)->forUser($this->storedAttachmentId, (int) auth()->id())
                    : app(AttachmentService::class)->createFromUpload($this->attachmentFile, userId: auth()->id());

                if (! $stored->isAvailable()) {
                    throw new InvalidArgumentException('Lampiran sudah kedaluwarsa atau tidak tersedia. Pilih file baru.');
                }

                $this->storedAttachmentId = (string) $stored->uuid;
                $this->attachmentKind = $stored->media_kind;
                $attachment = new OutboundAttachment(
                    kind: $stored->kind(),
                    url: 'attachment://'.$stored->uuid,
                    filename: $stored->original_filename,
                    mimeType: $stored->mime_type,
                    attachmentId: (string) $stored->uuid,
                    size: (int) $stored->size,
                );
            } elseif (trim($this->attachmentUrl) !== '') {
                $kind = AttachmentKind::tryFrom($this->attachmentKind);

                if ($kind === null) {
                    throw new InvalidArgumentException('Pilih jenis lampiran.');
                }

                app(AttachmentService::class)->assertPublicUrl($this->attachmentUrl);
                $attachment = new OutboundAttachment(
                    kind: $kind,
                    url: trim($this->attachmentUrl),
                );
            }

            if ($attachment === null && $body === '') {
                throw new InvalidArgumentException('Isi pesan atau pilih lampiran.');
            }

            if ($attachment?->kind === AttachmentKind::Audio && $body !== '') {
                throw new InvalidArgumentException('Audio tidak mendukung caption.');
            }
        } catch (InvalidArgumentException $exception) {
            $this->attachmentError = $exception->getMessage();
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        } catch (Throwable) {
            $this->attachmentError = 'Lampiran gagal disimpan.';
            Notification::make()->title('Lampiran gagal disimpan.')->danger()->send();

            return;
        }

        try {
            $dispatch = app(Inbox::class)->send($account, $target, $body, $attachment, $this->submissionUuid);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        if ($dispatch->result->errorCode === 'message_queued') {
            $this->draft = '';
            $this->clearAttachment();
            $this->resetSubmission();
            $this->composingNew = false;
            $this->chatId = $dispatch->chatId;
            $this->chatTitle = $this->chatTitle !== '' && $this->chatTitle !== 'Obrolan baru'
                ? $this->chatTitle
                : $dispatch->chatId;
            $this->status = 'Diantrikan lewat '.$account->name;
            Notification::make()->title('Pesan diantrikan.')->success()->send();
            $this->loadChats();
            $this->loadMessages();

            return;
        }

        if (! $dispatch->isAccepted()) {
            $this->composingNew = false;
            $this->chatId = $dispatch->chatId;
            $this->chatTitle = $this->chatTitle !== '' && $this->chatTitle !== 'Obrolan baru'
                ? $this->chatTitle
                : $dispatch->chatId;
            $this->loadChats();
            $this->loadMessages();
            Notification::make()
                ->title('Pesan belum terkirim')
                ->body($dispatch->result->errorMessage ?? 'Provider menolak atau tidak menjawab.')
                ->danger()
                ->send();

            return;
        }

        $this->draft = '';
        $this->clearAttachment();
        $this->resetSubmission();
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

    public function attachmentSizeLabel(): string
    {
        $bytes = (int) ($this->attachmentFile?->getSize() ?: 0);

        if ($bytes <= 0) {
            return 'ukuran tidak diketahui';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2, ',', '.').' MB';
        }

        return number_format(max(1, $bytes / 1024), 0, ',', '.').' KB';
    }

    public function attachmentPreviewUrl(): ?string
    {
        $kind = AttachmentKind::tryFrom($this->attachmentKind);

        if ($kind === null || $kind === AttachmentKind::Document) {
            return null;
        }

        if ($this->attachmentFile !== null) {
            try {
                return $this->attachmentFile->temporaryUrl();
            } catch (Throwable) {
                return null;
            }
        }

        $url = trim($this->attachmentUrl);

        if ($url === '') {
            return null;
        }

        try {
            app(AttachmentService::class)->assertPublicUrl($url);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $url;
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

    public function clearAttachment(): void
    {
        $this->attachmentFile = null;
        $this->attachmentUrl = '';
        $this->attachmentKind = 'document';
        $this->attachmentError = null;
        $this->resetSubmission();
    }

    public function updatedDraft(): void
    {
        $this->resetSubmission();
    }

    public function updatedNewRecipient(): void
    {
        $this->resetSubmission();
    }

    public function updatedProviderId(): void
    {
        $this->resetSubmission();
    }

    public function updatedAttachmentKind(): void
    {
        $this->resetSubmission();
        $this->attachmentError = null;
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

    private function resetSubmission(): void
    {
        $this->submissionUuid = (string) Str::uuid();
        $this->storedAttachmentId = null;
    }
}
