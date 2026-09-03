<?php

namespace App\Domain\Inbox;

final readonly class InboxChat
{
    public function __construct(
        public string $id,
        public string $title,
        public string $preview,
        public ?string $timestamp,
        public bool $isGroup = false,
        public ?bool $lastFromMe = null,
        public ?string $lastMessageId = null,
        public ?int $lastOccurredAt = null,
        public ?int $providerId = null,
        public ?string $providerName = null,
        public ?string $driver = null,
    ) {}

    /**
     * @return array{id: string, title: string, preview: string, timestamp: ?string, is_group: bool, last_from_me: ?bool, provider_id: ?int, provider_name: ?string, driver: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'preview' => $this->preview,
            'timestamp' => $this->timestamp,
            'is_group' => $this->isGroup,
            'last_from_me' => $this->lastFromMe,
            'provider_id' => $this->providerId,
            'provider_name' => $this->providerName,
            'driver' => $this->driver,
        ];
    }
}
