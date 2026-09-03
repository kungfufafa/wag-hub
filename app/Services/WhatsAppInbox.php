<?php

namespace App\Services;

use App\Contracts\WhatsApp\ProviderInboxReader;
use App\Domain\Delivery\OutboundText;
use App\Domain\Inbox\InboxDispatch;
use App\Domain\Inbox\InboxEvent;
use App\Infrastructure\WhatsApp\GowaInboxReader;
use App\Infrastructure\WhatsApp\InboxPayload;
use App\Infrastructure\WhatsApp\ProviderDriverManager;
use App\Infrastructure\WhatsApp\WahaInboxReader;
use App\Models\ProviderAccount;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

final readonly class WhatsAppInbox
{
    public function __construct(
        private WahaInboxReader $waha,
        private GowaInboxReader $gowa,
        private ProviderDriverManager $drivers,
        private PhoneNormalizer $phones,
        private InboxLedger $ledger,
    ) {}

    /**
     * @return Collection<int, ProviderAccount>
     */
    public function channels(): Collection
    {
        return ProviderAccount::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();
    }

    public function webhookUrl(ProviderAccount $account): string
    {
        return url('/webhooks/whatsapp/'.$account->uuid);
    }

    public function driverLabel(string $driver): string
    {
        return match ($driver) {
            'waha' => 'WAHA',
            'gowa' => 'GOWA',
            'fonnte' => 'Fonnte',
            'waba' => 'WABA',
            default => strtoupper($driver),
        };
    }

    /**
     * @return list<array{id: string, title: string, preview: string, timestamp: ?string, is_group: bool, last_from_me: ?bool, provider_id: ?int, provider_name: ?string, driver: ?string}>
     */
    public function chats(?string $search = null, ?ProviderAccount $account = null): array
    {
        $accounts = $account === null ? $this->channels() : collect([$account]);

        foreach ($accounts as $item) {
            $this->pullChats($item, $search);
        }

        return $this->ledger->chats($account, $search);
    }

    /**
     * @return list<array{id: string, body: string, from_me: bool, timestamp: ?string, kind: string}>
     */
    public function messages(ProviderAccount $account, string $chatId): array
    {
        if ($chatId === '') {
            return [];
        }

        $this->pullMessages($account, $chatId);

        return $this->ledger->messages($account, $chatId);
    }

    public function send(ProviderAccount $account, string $target, string $body): InboxDispatch
    {
        $body = trim($body);

        if ($body === '') {
            throw new InvalidArgumentException('Isi pesan tidak boleh kosong.');
        }

        $chatId = $this->normalizeChatId($account, $target);
        $recipient = explode('@', $chatId)[0] ?: $chatId;

        $result = $this->drivers->send($account, new OutboundText(
            recipient: $recipient,
            body: $body,
            chatId: $chatId,
        ));

        if ($result->isAccepted()) {
            $this->ledger->record($account, new InboxEvent(
                chatId: $chatId,
                messageId: $result->providerMessageId ?? ('local-'.bin2hex(random_bytes(8))),
                body: $body,
                fromMe: true,
                occurredAt: time(),
                title: $recipient,
                isGroup: InboxPayload::isGroup($chatId),
            ));
        }

        return new InboxDispatch($result, $chatId);
    }

    public function recordEvent(ProviderAccount $account, InboxEvent $event): void
    {
        $this->ledger->record($account, $event);
    }

    public function normalizeChatId(ProviderAccount $account, string $target): string
    {
        $target = trim($target);

        if ($target === '') {
            throw new InvalidArgumentException('Nomor tujuan tidak boleh kosong.');
        }

        if (str_contains($target, '@')) {
            return $target;
        }

        $phone = $this->phones->normalize($target);

        return match ($account->driver) {
            'waha' => $phone.'@c.us',
            'gowa' => $phone.'@s.whatsapp.net',
            default => $phone,
        };
    }

    private function pullChats(ProviderAccount $account, ?string $search): void
    {
        $reader = $this->reader($account);

        if ($reader === null) {
            return;
        }

        try {
            foreach ($reader->chats($account, $search) as $chat) {
                $this->ledger->rememberChat($account, $chat);
            }
        } catch (Throwable) {
            // Keep the local ledger when the gateway is unreachable.
        }
    }

    private function pullMessages(ProviderAccount $account, string $chatId): void
    {
        $reader = $this->reader($account);

        if ($reader === null) {
            return;
        }

        try {
            foreach ($this->messageChatIds($account, $chatId) as $candidate) {
                $pulled = $reader->messages($account, $candidate);

                foreach ($pulled as $message) {
                    $this->ledger->record($account, new InboxEvent(
                        chatId: $candidate,
                        messageId: $message->id,
                        body: $message->body,
                        fromMe: $message->fromMe,
                        occurredAt: $message->occurredAt ?? time(),
                        kind: $message->kind,
                    ));
                }

                if ($pulled !== []) {
                    break;
                }
            }
        } catch (Throwable) {
            // Keep the local ledger when the gateway is unreachable.
        }
    }

    /**
     * @return list<string>
     */
    private function messageChatIds(ProviderAccount $account, string $chatId): array
    {
        if (str_contains($chatId, '@g.us') || str_contains($chatId, '@broadcast')) {
            return [$chatId];
        }

        $peer = InboxPayload::peerKey($chatId);
        $native = match ($account->driver) {
            'waha' => $peer.'@c.us',
            'gowa' => $peer.'@s.whatsapp.net',
            default => $peer,
        };

        return array_values(array_unique([$chatId, $native, $peer]));
    }

    private function reader(ProviderAccount $account): ?ProviderInboxReader
    {
        return match ($account->driver) {
            'waha' => $this->waha,
            'gowa' => $this->gowa,
            default => null,
        };
    }
}
