<?php

namespace App\Domain\Inbox;

final readonly class InboxMessage
{
    public function __construct(
        public string $id,
        public string $body,
        public bool $fromMe,
        public ?string $timestamp,
        public string $kind = 'text',
        public ?int $occurredAt = null,
    ) {}

    /**
     * @return array{id: string, body: string, from_me: bool, timestamp: ?string, kind: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'body' => $this->body,
            'from_me' => $this->fromMe,
            'timestamp' => $this->timestamp,
            'kind' => $this->kind,
        ];
    }
}
