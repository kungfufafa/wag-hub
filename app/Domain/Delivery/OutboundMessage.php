<?php

namespace App\Domain\Delivery;

/**
 * Provider-agnostic outbound message. `body` is the text for plain messages
 * and the caption (possibly empty) when an attachment is present.
 */
readonly class OutboundMessage
{
    public function __construct(
        public string $recipient,
        public string $body,
        public ?OutboundAttachment $attachment = null,
        public ?string $chatId = null,
        public ?string $idempotencyKey = null,
    ) {}

    public function hasAttachment(): bool
    {
        return $this->attachment !== null;
    }

    public function hasBody(): bool
    {
        return trim($this->body) !== '';
    }

    /**
     * Caption to send alongside an attachment, or null when the text is empty
     * or the attachment kind cannot carry one.
     */
    public function caption(): ?string
    {
        if (! $this->hasBody() || ($this->attachment !== null && ! $this->attachment->kind->supportsCaption())) {
            return null;
        }

        return $this->body;
    }

    public function wahaChatId(): string
    {
        return $this->chatId ?? ($this->recipient.'@c.us');
    }

    public function gowaPhone(): string
    {
        return $this->chatId ?? ($this->recipient.'@s.whatsapp.net');
    }
}
