<?php

namespace App\Services;

use App\Domain\Inbox\InboxChat;
use App\Domain\Inbox\InboxEvent;
use App\Domain\Inbox\InboxMessage as InboxMessageView;
use App\Infrastructure\WhatsApp\InboxPayload;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\ProviderAccount;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

final class InboxLedger
{
    public function record(ProviderAccount $account, InboxEvent $event): void
    {
        $conversation = $this->conversation($account, $event->chatId, $event->title, $event->isGroup);
        $messageId = mb_substr($event->messageId, 0, 190);
        $existing = InboxMessage::query()
            ->where('inbox_conversation_id', $conversation->id)
            ->where('provider_message_id', $messageId)
            ->first();

        if ($existing === null) {
            InboxMessage::query()->create([
                'inbox_conversation_id' => $conversation->id,
                'provider_message_id' => $messageId,
                'from_me' => $event->fromMe,
                'body' => $event->body,
                'kind' => $event->kind,
                'occurred_at' => Carbon::createFromTimestamp($event->occurredAt),
            ]);
        } elseif ($existing->body === '' && $event->body !== '') {
            $existing->update(['body' => $event->body]);
        }

        $this->touchConversation($conversation, $event);
    }

    public function rememberChat(ProviderAccount $account, InboxChat $chat): void
    {
        $conversation = $this->conversation($account, $chat->id, $chat->title, $chat->isGroup);

        if ($this->isBetterTitle($conversation->title, $chat->title, $conversation->chat_id)) {
            $conversation->title = $chat->title;
        }

        if ($chat->preview !== '') {
            $occurred = $chat->lastOccurredAt !== null
                ? Carbon::createFromTimestamp($chat->lastOccurredAt)
                : null;

            if ($occurred === null || $conversation->last_message_at === null || $occurred->greaterThanOrEqualTo($conversation->last_message_at)) {
                $conversation->preview = mb_substr($chat->preview, 0, 500);

                if ($chat->lastFromMe !== null) {
                    $conversation->last_from_me = $chat->lastFromMe;
                }

                if ($occurred !== null) {
                    $conversation->last_message_at = $occurred;
                }
            }
        }

        $conversation->save();

        if ($chat->lastMessageId !== null && $chat->preview !== '') {
            $this->record($account, new InboxEvent(
                chatId: $chat->id,
                messageId: $chat->lastMessageId,
                body: $chat->preview,
                fromMe: $chat->lastFromMe ?? false,
                occurredAt: $chat->lastOccurredAt ?? time(),
                title: $chat->title,
                isGroup: $chat->isGroup,
            ));
        }
    }

    /**
     * @return list<array{id: string, title: string, preview: string, timestamp: ?string, is_group: bool, last_from_me: ?bool, provider_id: ?int, provider_name: ?string, driver: ?string}>
     */
    public function chats(?ProviderAccount $account = null, ?string $search = null): array
    {
        $query = InboxConversation::query()
            ->with('providerAccount')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');

        if ($account !== null) {
            $query->where('provider_account_id', $account->id);
        }

        $seen = [];
        $chats = [];

        foreach ($query->get() as $row) {
            $provider = $row->providerAccount;

            if ($provider === null) {
                continue;
            }

            $seenKey = $row->provider_account_id.'|'.$row->peer_key;

            if (isset($seen[$seenKey])) {
                continue;
            }

            $seen[$seenKey] = true;

            if (filled($search)) {
                $haystack = mb_strtolower(
                    $row->title.' '.$row->chat_id.' '.$row->peer_key.' '.$row->preview.' '.$provider->name.' '.$provider->driver,
                );

                if (! str_contains($haystack, mb_strtolower($search))) {
                    continue;
                }
            }

            $chats[] = (new InboxChat(
                id: $row->chat_id,
                title: $row->title,
                preview: $row->preview,
                timestamp: $row->last_message_at?->timezone((string) config('app.timezone'))->format('d M H.i'),
                isGroup: $row->is_group,
                lastFromMe: $row->last_from_me,
                providerId: $provider->id,
                providerName: $provider->name,
                driver: $provider->driver,
            ))->toArray();
        }

        return $chats;
    }

    /**
     * @return list<array{id: string, body: string, from_me: bool, timestamp: ?string, kind: string}>
     */
    public function messages(ProviderAccount $account, string $chatId): array
    {
        $peer = InboxPayload::peerKey($chatId);
        $conversationIds = InboxConversation::query()
            ->where('provider_account_id', $account->id)
            ->where(function ($query) use ($chatId, $peer): void {
                $query->where('chat_id', $chatId)->orWhere('peer_key', $peer);
            })
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return [];
        }

        return InboxMessage::query()
            ->whereIn('inbox_conversation_id', $conversationIds)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->map(fn (InboxMessage $message): array => (new InboxMessageView(
                id: $message->provider_message_id,
                body: $message->body,
                fromMe: $message->from_me,
                timestamp: $message->occurred_at?->timezone((string) config('app.timezone'))->format('d M H.i'),
                kind: $message->kind,
                occurredAt: $message->occurred_at?->getTimestamp(),
            ))->toArray())
            ->all();
    }

    private function conversation(
        ProviderAccount $account,
        string $chatId,
        ?string $title,
        bool $isGroup,
    ): InboxConversation {
        $exact = InboxConversation::query()
            ->where('provider_account_id', $account->id)
            ->where('chat_id', $chatId)
            ->first();

        if ($exact !== null) {
            return $exact;
        }

        $peer = InboxPayload::peerKey($chatId);
        $byPeer = InboxConversation::query()
            ->where('provider_account_id', $account->id)
            ->where('peer_key', $peer)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if ($byPeer !== null) {
            return $byPeer;
        }

        try {
            return InboxConversation::query()->create([
                'provider_account_id' => $account->id,
                'chat_id' => $chatId,
                'peer_key' => $peer,
                'title' => $title ?: InboxPayload::displayName($chatId),
                'preview' => '',
                'is_group' => $isGroup || InboxPayload::isGroup($chatId),
                'last_from_me' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            return InboxConversation::query()
                ->where('provider_account_id', $account->id)
                ->where('chat_id', $chatId)
                ->firstOrFail();
        }
    }

    private function touchConversation(InboxConversation $conversation, InboxEvent $event): void
    {
        $occurred = Carbon::createFromTimestamp($event->occurredAt);

        if ($conversation->last_message_at === null || $occurred->greaterThan($conversation->last_message_at)) {
            $conversation->preview = mb_substr($event->body, 0, 500);
            $conversation->last_from_me = $event->fromMe;
            $conversation->last_message_at = $occurred;
        }

        if ($event->title !== null && $this->isBetterTitle($conversation->title, $event->title, $conversation->chat_id)) {
            $conversation->title = $event->title;
        }

        $conversation->is_group = $conversation->is_group || $event->isGroup;
        $conversation->save();
    }

    private function isBetterTitle(string $current, string $incoming, string $chatId): bool
    {
        if ($incoming === '' || $incoming === $chatId) {
            return false;
        }

        return $current === ''
            || $current === $chatId
            || $current === InboxPayload::peerKey($chatId);
    }
}
