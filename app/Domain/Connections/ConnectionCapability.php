<?php

namespace App\Domain\Connections;

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

    public function label(): string
    {
        return match ($this) {
            self::SendText => 'Kirim teks',
            self::SendImage => 'Kirim gambar',
            self::SendDocument => 'Kirim dokumen',
            self::SendVideo => 'Kirim video',
            self::SendAudio => 'Kirim audio',
            self::NumberLookup => 'Cek nomor',
            self::InboundMessages => 'Pesan masuk',
            self::DeliveryStatus => 'Status pengiriman',
        };
    }

    public static function forMessageType(string $type): ?self
    {
        return match ($type) {
            'text' => self::SendText,
            'image' => self::SendImage,
            'document' => self::SendDocument,
            'video' => self::SendVideo,
            'audio' => self::SendAudio,
            default => null,
        };
    }
}
