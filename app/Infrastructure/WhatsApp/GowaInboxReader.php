<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderInboxReader;
use App\Domain\Inbox\InboxChat;
use App\Domain\Inbox\InboxMessage;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class GowaInboxReader implements ProviderInboxReader
{
    public function __construct(
        private ProviderEndpointGuard $endpoints,
    ) {}

    public function chats(ProviderAccount $account, ?string $search = null): array
    {
        $query = ['limit' => 40, 'offset' => 0];

        if (filled($search)) {
            $query['search'] = $search;
        }

        $rows = InboxPayload::rows($this->getJson($account, '/chats', $query));
        $chats = [];

        foreach ($rows as $row) {
            $id = InboxPayload::string($row['jid'] ?? $row['chat_jid'] ?? $row['id'] ?? null);

            if ($id === null) {
                continue;
            }

            $occurredAt = InboxPayload::unix($row['last_message_time'] ?? $row['updated_at'] ?? $row['timestamp'] ?? null);

            $chats[] = new InboxChat(
                id: $id,
                title: InboxPayload::title($row, $id),
                preview: InboxPayload::string($row['last_message'] ?? $row['preview'] ?? null) ?? '',
                timestamp: InboxPayload::displayTime($occurredAt),
                isGroup: InboxPayload::isGroup($id),
                lastOccurredAt: $occurredAt,
            );
        }

        return $chats;
    }

    public function messages(ProviderAccount $account, string $chatId): array
    {
        if ($chatId === '') {
            return [];
        }

        $payload = $this->getJson($account, '/chat/'.rawurlencode($chatId).'/messages', [
            'limit' => 50,
            'offset' => 0,
        ]);
        $messages = [];

        foreach (InboxPayload::rows($payload) as $row) {
            $row['chatId'] ??= $chatId;
            $event = InboxPayload::eventFromGowaRow($row);

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

        $configuration = $this->configuration($account);
        $username = InboxPayload::string($configuration['username'] ?? null) ?? '';
        $password = InboxPayload::string($configuration['password'] ?? null) ?? '';

        $request = Http::acceptJson()
            ->withBasicAuth($username, $password)
            ->withoutRedirecting()
            ->timeout($this->timeout($account))
            ->connectTimeout(min(5, $this->timeout($account)));

        $deviceId = InboxPayload::string($configuration['device_id'] ?? null);

        if ($deviceId !== null) {
            $request = $request->withHeaders(['X-Device-Id' => $deviceId]);
        }

        return $request;
    }

    private function url(ProviderAccount $account, string $path): string
    {
        return rtrim((string) ($this->configuration($account)['base_url'] ?? ''), '/').$path;
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
