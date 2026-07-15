<?php

namespace App\Domain\Delivery;

final readonly class OutboundText
{
    public function __construct(
        public string $recipient,
        public string $body,
    ) {}

    public function wahaChatId(): string
    {
        return $this->recipient.'@c.us';
    }
}
