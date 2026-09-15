<?php

namespace App\Domain\Delivery;

/**
 * Backward-compatible text-only DTO for integrations that used the previous
 * provider contract. It is accepted anywhere an OutboundMessage is expected.
 */
final readonly class OutboundText extends OutboundMessage
{
    public function __construct(
        string $recipient,
        string $body,
        ?string $chatId = null,
    ) {
        parent::__construct(
            recipient: $recipient,
            body: $body,
            chatId: $chatId,
        );
    }
}
