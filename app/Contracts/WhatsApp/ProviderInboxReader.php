<?php

namespace App\Contracts\WhatsApp;

use App\Domain\Inbox\InboxChat;
use App\Domain\Inbox\InboxMessage;
use App\Models\ProviderAccount;

interface ProviderInboxReader
{
    /**
     * @return list<InboxChat>
     */
    public function chats(ProviderAccount $account, ?string $search = null): array;

    /**
     * @return list<InboxMessage>
     */
    public function messages(ProviderAccount $account, string $chatId): array;
}
