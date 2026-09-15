<?php

namespace App\Domain\Delivery;

enum AttachmentKind: string
{
    case Image = 'image';
    case Document = 'document';
    case Video = 'video';
    case Audio = 'audio';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    public function supportsCaption(): bool
    {
        return $this !== self::Audio;
    }

    public function supportsFilename(): bool
    {
        return $this === self::Document;
    }

    public function defaultMimeType(): string
    {
        return match ($this) {
            self::Image => 'image/jpeg',
            self::Document => 'application/octet-stream',
            self::Video => 'video/mp4',
            self::Audio => 'audio/mpeg',
        };
    }
}
