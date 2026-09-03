<?php

namespace App\Domain\Delivery;

final readonly class OutboundText
{
    public function __construct(
        public string $recipient,
        public string $body,
        public ?string $chatId = null,
    ) {}

    public function wahaChatId(): string
    {
        return $this->chatId ?? ($this->recipient.'@c.us');
    }

    public function gowaPhone(): string
    {
        return $this->chatId ?? ($this->recipient.'@s.whatsapp.net');
    }
}
