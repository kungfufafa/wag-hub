<?php

namespace App\Domain\Delivery;

use InvalidArgumentException;

final readonly class OutboundAttachment
{
    private const EXTENSION_MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'csv' => 'text/csv',
        'txt' => 'text/plain',
        'zip' => 'application/zip',
        'mp4' => 'video/mp4',
        '3gp' => 'video/3gpp',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'aac' => 'audio/aac',
        'm4a' => 'audio/mp4',
        'amr' => 'audio/amr',
    ];

    public function __construct(
        public AttachmentKind $kind,
        public string $url,
        public ?string $filename = null,
        public ?string $mimeType = null,
        public ?string $attachmentId = null,
        public ?int $size = null,
    ) {
        if (trim($url) === '' && $attachmentId === null) {
            throw new InvalidArgumentException('Attachment URL may not be empty.');
        }
    }

    /**
     * Rebuild from the persisted ledger representation. Returns null when the
     * payload is missing or malformed so a corrupt row degrades to text-only.
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        $kind = AttachmentKind::tryFrom((string) ($data['kind'] ?? ''));
        $url = $data['url'] ?? null;
        $attachmentId = $data['id'] ?? $data['attachment_id'] ?? null;
        $attachmentId = is_scalar($attachmentId) && trim((string) $attachmentId) !== ''
            ? trim((string) $attachmentId)
            : null;

        if ($kind === null || (! is_string($url) && $attachmentId === null)) {
            return null;
        }

        $url = is_string($url) && trim($url) !== ''
            ? trim($url)
            : 'attachment://'.$attachmentId;

        return new self(
            kind: $kind,
            url: $url,
            filename: self::optionalString($data['filename'] ?? null),
            mimeType: self::optionalString($data['mime_type'] ?? null),
            attachmentId: $attachmentId,
            size: is_numeric($data['size'] ?? null) ? (int) $data['size'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'kind' => $this->kind->value,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
        ];

        if ($this->attachmentId !== null) {
            return [
                'id' => $this->attachmentId,
                ...$data,
                'size' => $this->size,
            ];
        }

        return [
            'kind' => $this->kind->value,
            'url' => $this->url,
            'filename' => $this->filename,
            'mime_type' => $this->mimeType,
        ];
    }

    /**
     * Filename to present to the recipient: explicit value, else the last URL
     * path segment, else a generic name derived from the attachment kind.
     */
    public function resolvedFilename(): string
    {
        if ($this->filename !== null) {
            return $this->filename;
        }

        $path = parse_url($this->url, PHP_URL_PATH);
        $basename = is_string($path) ? rawurldecode(basename($path)) : '';

        if ($basename !== '' && $basename !== '/' && str_contains($basename, '.')) {
            return $basename;
        }

        return $this->kind->value.'.'.$this->guessExtension();
    }

    /**
     * MIME type to advertise to providers that require one (WAHA).
     */
    public function resolvedMimeType(): string
    {
        if ($this->mimeType !== null) {
            return $this->mimeType;
        }

        $extension = strtolower(pathinfo($this->resolvedFilename(), PATHINFO_EXTENSION));

        return self::EXTENSION_MIME_TYPES[$extension] ?? $this->kind->defaultMimeType();
    }

    private function guessExtension(): string
    {
        return match ($this->kind) {
            AttachmentKind::Image => 'jpg',
            AttachmentKind::Document => 'bin',
            AttachmentKind::Video => 'mp4',
            AttachmentKind::Audio => 'mp3',
        };
    }

    private static function optionalString(mixed $value): ?string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }
}
