<?php

namespace App\Domain\Inbox;

use App\Domain\Delivery\ProviderResult;

final readonly class InboxDispatch
{
    public function __construct(
        public ProviderResult $result,
        public string $chatId,
    ) {}

    public function isAccepted(): bool
    {
        return $this->result->isAccepted();
    }
}
