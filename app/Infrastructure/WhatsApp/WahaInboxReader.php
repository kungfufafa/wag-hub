<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderInboxReader;
use App\Domain\Inbox\InboxChat;
use App\Domain\Inbox\InboxMessage;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class WahaInboxReader implements ProviderInboxReader
{
    public function __construct(
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function chats(ProviderAccount $account, ?string $search = null): array
    {
        $session = $this->session($account);

        if ($session === null) {
            return [];
        }

        $overview = $this->getJson($account, '/api/'.rawurlencode($session).'/chats/overview', [
            'limit' => 40,
            'offset' => 0,
        ]);
        $rows = InboxPayload::rows($overview);

        if ($rows === []) {
            $rows = InboxPayload::rows($this->getJson($account, '/api/'.rawurlencode($session).'/chats', [
                'limit' => 40,
                'offset' => 0,
            ]));
        }

        $chats = [];

        foreach ($rows as $row) {
            $id = InboxPayload::string($row['id'] ?? $row['chatId'] ?? $row['jid'] ?? null);

            if ($id === null) {
                continue;
            }

            $last = is_array($row['lastMessage'] ?? null) ? $row['lastMessage'] : [];
            $preview = InboxPayload::body($last) ?? InboxPayload::string($row['preview'] ?? null) ?? '';
            $occurredAt = InboxPayload::unix($last['timestamp'] ?? $row['conversationTimestamp'] ?? $row['timestamp'] ?? null);
            $title = InboxPayload::title($row, $id);

            if (filled($search) && ! str_contains(mb_strtolower($title.' '.$id), mb_strtolower($search))) {
                continue;
            }

            $chats[] = new InboxChat(
                id: $id,
                title: $title,
                preview: $preview,
                timestamp: InboxPayload::displayTime($occurredAt),
                isGroup: InboxPayload::isGroup($id),
                lastFromMe: $last === [] ? null : InboxPayload::fromMe($last),
                lastMessageId: $last === [] ? null : InboxPayload::stringifyId(
                    $last['id'] ?? data_get($last, 'key.id') ?? data_get($last, 'id._serialized'),
                ),
                lastOccurredAt: $occurredAt,
            );
        }

        return $chats;
    }

    public function messages(ProviderAccount $account, string $chatId): array
    {
        $session = $this->session($account);

        if ($session === null || $chatId === '') {
            return [];
        }

        $payload = $this->getJson(
            $account,
            '/api/'.rawurlencode($session).'/chats/'.rawurlencode($chatId).'/messages',
            [
                'limit' => 50,
                'downloadMedia' => 'false',
            ],
        );

        $messages = [];

        foreach (InboxPayload::rows($payload) as $row) {
            $row['chatId'] ??= $chatId;
            $event = InboxPayload::eventFromWahaRow($row);

            if ($event === null) {
                continue;
            }

            $messages[] = $event->toMessage();
        }

        usort($messages, function (InboxMessage $left, InboxMessage $right): int {
            return ($left->occurredAt ?? 0) <=> ($right->occurredAt ?? 0);
        });

        return array_values($messages);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function getJson(ProviderAccount $account, string $path, array $query = []): mixed
    {
        try {
            $response = $this->request($account, $path)->get($this->url($account, $path), $query);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        return $response->json();
    }

    private function request(ProviderAccount $account, string $path): PendingRequest
    {
        $this->endpoints->assertAllowed($this->url($account, $path));

        $request = Http::acceptJson()
            ->withoutRedirecting()
            ->timeout($this->timeout($account))
            ->connectTimeout(min(5, $this->timeout($account)));

        $apiKey = InboxPayload::string(($this->configuration($account)['api_key'] ?? null));

        if ($apiKey !== null) {
            $request = $request->withHeaders(['X-Api-Key' => $apiKey]);
        }

        return $request;
    }

    private function url(ProviderAccount $account, string $path): string
    {
        return rtrim((string) ($this->configuration($account)['base_url'] ?? ''), '/').$path;
    }

    private function session(ProviderAccount $account): ?string
    {
        return InboxPayload::string($this->configuration($account)['session'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function configuration(ProviderAccount $account): array
    {
        return is_array($account->configuration) ? $account->configuration : [];
    }

    private function timeout(ProviderAccount $account): int
    {
        return max(1, min(60, (int) ($account->timeout_seconds ?: 15)));
    }
}
