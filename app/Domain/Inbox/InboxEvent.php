<?php

namespace App\Domain\Inbox;

final readonly class InboxEvent
{
    public function __construct(
        public string $chatId,
        public string $messageId,
        public string $body,
        public bool $fromMe,
        public int $occurredAt,
        public string $kind = 'text',
        public ?string $title = null,
        public bool $isGroup = false,
    ) {}

    public function toMessage(): InboxMessage
    {
        return new InboxMessage(
            id: $this->messageId,
            body: $this->body,
            fromMe: $this->fromMe,
            timestamp: date('d M H.i', $this->occurredAt),
            kind: $this->kind,
            occurredAt: $this->occurredAt,
        );
    }
}
