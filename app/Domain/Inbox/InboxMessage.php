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
        /** @var array<string, mixed>|null */
        public ?array $attachment = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'body' => $this->body,
            'from_me' => $this->fromMe,
            'timestamp' => $this->timestamp,
            'kind' => $this->kind,
        ];

        if ($this->attachment !== null) {
            $data['attachment'] = $this->attachment;
        }

        return $data;
    }
}
