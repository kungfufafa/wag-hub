<?php

namespace App\Domain\Connection;

enum ConnectionCapability: string
{
    case SendText = 'send_text';
    case SendImage = 'send_image';
    case SendDocument = 'send_document';
    case SendVideo = 'send_video';
    case SendAudio = 'send_audio';
    case NumberLookup = 'number_lookup';
    case InboundMessages = 'inbound_messages';
    case DeliveryStatus = 'delivery_status';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $capability): string => $capability->value,
            self::cases(),
        );
    }
}
