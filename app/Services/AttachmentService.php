<?php

namespace App\Services;

use App\Domain\Delivery\AttachmentKind;
use App\Domain\Delivery\OutboundAttachment;
use App\Models\Attachment;
use App\Models\GatewayMessage;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AttachmentService
{
    public const MAX_BYTES = 16777216;

    public const RETENTION_DAYS = 90;

    public const ORPHAN_HOURS = 24;

    /** @var array<string, string> */
    private const MIME_KINDS = [
        'image/jpeg' => 'image',
        'image/png' => 'image',
        'image/webp' => 'image',
        'image/gif' => 'image',
        'video/mp4' => 'video',
        'video/3gpp' => 'video',
        'audio/mpeg' => 'audio',
        'audio/ogg' => 'audio',
        'audio/opus' => 'audio',
        'audio/aac' => 'audio',
        'audio/mp4' => 'audio',
        'audio/amr' => 'audio',
        'application/pdf' => 'document',
        'application/msword' => 'document',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'document',
        'application/vnd.ms-excel' => 'document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'document',
        'application/vnd.ms-powerpoint' => 'document',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'document',
        'text/csv' => 'document',
        'text/plain' => 'document',
        'application/zip' => 'document',
    ];

    /** @var array<string, string> */
    private const EXTENSION_KINDS = [
        'jpg' => 'image', 'jpeg' => 'image', 'png' => 'image', 'webp' => 'image', 'gif' => 'image',
        'mp4' => 'video', '3gp' => 'video',
        'mp3' => 'audio', 'ogg' => 'audio', 'opus' => 'audio', 'aac' => 'audio', 'm4a' => 'audio', 'amr' => 'audio',
        'pdf' => 'document', 'doc' => 'document', 'docx' => 'document', 'xls' => 'document', 'xlsx' => 'document',
        'ppt' => 'document', 'pptx' => 'document', 'csv' => 'document', 'txt' => 'document', 'zip' => 'document',
    ];

    /** @var array<string, string> */
    private const EXTENSION_MIME_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        '3gp' => 'video/3gpp',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'aac' => 'audio/aac',
        'm4a' => 'audio/mp4',
        'amr' => 'audio/amr',
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
    ];

    public function createFromUpload(
        UploadedFile $file,
        ?int $clientApplicationId = null,
        ?int $userId = null,
    ): Attachment {
        $size = (int) ($file->getSize() ?: 0);
        $mimeType = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType()));
        $filename = $this->safeFilename($file->getClientOriginalName());
        $kind = $this->detectKind($mimeType, $filename);

        if ($size <= 0) {
            throw new InvalidArgumentException('File attachment tidak boleh kosong.');
        }

        if ($size > $this->maxBytes()) {
            throw new InvalidArgumentException('Ukuran file maksimal 16 MB.');
        }

        if ($kind === null) {
            throw new InvalidArgumentException('Format file belum didukung.');
        }

        $mimeType = $this->normalizedMimeType($mimeType, $filename);

        $uuid = (string) Str::uuid();
        $directory = 'attachments/'.$uuid;
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        $storageFilename = (string) Str::uuid().($extension !== '' ? '.'.$extension : '');
        $path = $file->storeAs($directory, $storageFilename, [
            'disk' => $this->disk(),
            'visibility' => 'private',
        ]);

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('File attachment gagal disimpan.');
        }

        try {
            $checksum = hash_file('sha256', $file->getRealPath());
        } catch (Throwable) {
            Storage::disk($this->disk())->delete($path);
            throw new RuntimeException('Checksum file attachment gagal dibuat.');
        }

        if (! is_string($checksum)) {
            Storage::disk($this->disk())->delete($path);
            throw new RuntimeException('Checksum file attachment gagal dibuat.');
        }

        try {
            return Attachment::query()->create([
                'uuid' => $uuid,
                'client_application_id' => $clientApplicationId,
                'user_id' => $userId,
                'disk' => $this->disk(),
                'path' => $path,
                'original_filename' => $filename,
                'mime_type' => $mimeType !== '' ? $mimeType : 'application/octet-stream',
                'media_kind' => $kind->value,
                'size' => $size,
                'checksum' => $checksum,
                'status' => 'active',
            ]);
        } catch (Throwable $exception) {
            Storage::disk($this->disk())->delete($path);
            throw $exception;
        }
    }

    public function forClient(string $uuid, int $clientApplicationId): Attachment
    {
        return Attachment::query()
            ->where('uuid', $uuid)
            ->where('client_application_id', $clientApplicationId)
            ->firstOrFail();
    }

    public function forUser(string $uuid, int $userId): Attachment
    {
        return Attachment::query()
            ->where('uuid', $uuid)
            ->where('user_id', $userId)
            ->firstOrFail();
    }

    public function resolve(OutboundAttachment $attachment): OutboundAttachment
    {
        if ($attachment->attachmentId === null) {
            return $attachment;
        }

        $stored = Attachment::query()->where('uuid', $attachment->attachmentId)->first();

        if ($stored === null || ! $stored->isAvailable()) {
            throw new FileNotFoundException('Attachment sudah tidak tersedia.');
        }

        // Verify the persisted object before issuing a URL. This keeps a
        // missing or modified file from being silently sent as a message with
        // the original metadata.
        $this->path($stored);
        $this->markReferenced($stored);

        return new OutboundAttachment(
            kind: $stored->kind(),
            url: $this->temporaryUrl($stored),
            filename: $attachment->filename ?? $stored->original_filename,
            mimeType: $attachment->mimeType ?? $stored->mime_type,
            attachmentId: $stored->uuid,
            size: (int) $stored->size,
        );
    }

    public function markReferenced(Attachment|string $attachment): Attachment
    {
        $stored = is_string($attachment)
            ? Attachment::query()->where('uuid', $attachment)->firstOrFail()
            : $attachment;

        // Suspend any previous retention deadline while a new delivery is
        // queued or processing. Terminal states call finalizeReference().
        $stored->forceFill([
            'last_referenced_at' => now(),
            'expires_at' => null,
        ])->saveQuietly();

        return $stored;
    }

    /**
     * Start a fresh retention window once the message that references a file
     * reaches a terminal delivery state.
     */
    public function finalizeReference(Attachment|string $attachment): Attachment
    {
        $stored = is_string($attachment)
            ? Attachment::query()->where('uuid', $attachment)->firstOrFail()
            : $attachment;

        $stored->forceFill([
            'last_referenced_at' => now(),
            'expires_at' => now()->addDays($this->retentionDays()),
        ])->saveQuietly();

        return $stored;
    }

    public function temporaryUrl(Attachment $attachment): string
    {
        return URL::temporarySignedRoute(
            'attachments.fetch',
            now()->addDay(),
            [
                'uuid' => $attachment->uuid,
                'filename' => $attachment->original_filename,
            ],
        );
    }

    public function path(Attachment $attachment): string
    {
        if (! $attachment->isAvailable()) {
            throw new FileNotFoundException('Attachment sudah tidak tersedia.');
        }

        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            throw new FileNotFoundException('File attachment tidak ditemukan.');
        }

        try {
            $path = $disk->path($attachment->path);
            $size = filesize($path);
            $checksum = hash_file('sha256', $path);
        } catch (Throwable) {
            throw new FileNotFoundException('File attachment tidak dapat dibaca.');
        }

        if ($size === false || (int) $size !== (int) $attachment->size
            || ! is_string($checksum)
            || ! hash_equals((string) $attachment->checksum, $checksum)) {
            throw new FileNotFoundException('File attachment rusak atau checksum tidak sesuai.');
        }

        return $path;
    }

    public function cleanup(): int
    {
        $count = 0;
        $now = now();
        $pendingAttachmentIds = $this->pendingAttachmentIds();

        Attachment::query()
            ->where('status', 'active')
            ->where(function ($query) use ($now): void {
                $query->where(function ($nested) use ($now): void {
                    $nested->whereNull('last_referenced_at')
                        ->where('created_at', '<=', $now->copy()->subHours($this->orphanHours()));
                })->orWhere(function ($nested) use ($now): void {
                    $nested->whereNotNull('expires_at')
                        ->where('expires_at', '<=', $now);
                })->orWhere(function ($nested) use ($now): void {
                    $nested->whereNotNull('last_referenced_at')
                        ->whereNull('expires_at')
                        ->where('last_referenced_at', '<=', $now->copy()->subDays($this->retentionDays()));
                });
            })
            ->chunkById(100, function ($attachments) use (&$count, $pendingAttachmentIds): void {
                foreach ($attachments as $attachment) {
                    if (in_array((string) $attachment->uuid, $pendingAttachmentIds, true)) {
                        continue;
                    }

                    Storage::disk($attachment->disk)->delete($attachment->path);
                    $attachment->forceFill([
                        'status' => 'expired',
                        'deleted_at' => now(),
                    ])->saveQuietly();
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Encrypted attachment references cannot be filtered by SQL. Build the
     * small protection set once per cleanup run so queued/processing messages
     * keep their files even when an old expiry timestamp has passed.
     *
     * @return list<string>
     */
    private function pendingAttachmentIds(): array
    {
        $ids = [];

        foreach (GatewayMessage::query()
            ->whereIn('status', ['queued', 'processing'])
            ->select(['attachment'])
            ->cursor() as $message) {
            $attachment = $message->attachment;
            $id = is_array($attachment) ? ($attachment['id'] ?? null) : null;

            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return array_values(array_unique($ids));
    }

    public function assertPublicUrl(string $url): void
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts)) {
            throw new InvalidArgumentException('URL lampiran harus berupa HTTP(S) yang valid.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new InvalidArgumentException('URL lampiran harus berupa HTTP(S) yang valid.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new InvalidArgumentException('URL lampiran harus dapat diakses publik.');
        }

        if (str_contains($host, '0x')
            && preg_match('/\A(?:0x[0-9a-f]+|\d+)(?:\.(?:0x[0-9a-f]+|\d+)){0,3}\z/', $host) === 1) {
            $normalized = $this->dottedIpv4FromLiteral($host);

            if ($normalized === null) {
                throw new InvalidArgumentException('URL lampiran harus dapat diakses publik.');
            }

            $host = $normalized;
        }

        // Reject decimal/octal-looking host literals that can represent a
        // private IPv4 address while bypassing FILTER_VALIDATE_IP.
        if (preg_match('/\A[0-9.]+\z/', $host) === 1
            && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('URL lampiran harus dapat diakses publik.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('URL lampiran harus dapat diakses publik.');
            }

            return;
        }

        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (is_string($ip)
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new InvalidArgumentException('URL lampiran harus dapat diakses publik.');
            }
        }
    }

    /**
     * @return list<string>
     */
    public static function acceptedMimeTypes(): array
    {
        return array_keys(self::MIME_KINDS);
    }

    public function disk(): string
    {
        return (string) config('gateway.attachments.disk', 'local');
    }

    public function maxBytes(): int
    {
        return min(self::MAX_BYTES, max(1, (int) config('gateway.attachments.max_bytes', self::MAX_BYTES)));
    }

    public function retentionDays(): int
    {
        return max(1, (int) config('gateway.attachments.retention_days', self::RETENTION_DAYS));
    }

    public function orphanHours(): int
    {
        return max(1, (int) config('gateway.attachments.orphan_hours', self::ORPHAN_HOURS));
    }

    /**
     * Convert a hexadecimal or mixed-base IPv4 literal to dotted-decimal.
     */
    private function dottedIpv4FromLiteral(string $host): ?string
    {
        $parts = explode('.', $host);
        $count = count($parts);
        $max = match ($count) {
            1 => [0xFFFFFFFF],
            2 => [0xFF, 0xFFFFFF],
            3 => [0xFF, 0xFF, 0xFFFF],
            4 => [0xFF, 0xFF, 0xFF, 0xFF],
            default => null,
        };

        if ($max === null) {
            return null;
        }

        $values = [];

        foreach ($parts as $index => $part) {
            $value = $this->parseIpv4Part($part);

            if ($value === null || $value > $max[$index]) {
                return null;
            }

            $values[] = $value;
        }

        $address = match ($count) {
            1 => $values[0],
            2 => ($values[0] << 24) | $values[1],
            3 => ($values[0] << 24) | ($values[1] << 16) | $values[2],
            default => ($values[0] << 24) | ($values[1] << 16) | ($values[2] << 8) | $values[3],
        };

        return sprintf(
            '%d.%d.%d.%d',
            ($address >> 24) & 0xFF,
            ($address >> 16) & 0xFF,
            ($address >> 8) & 0xFF,
            $address & 0xFF,
        );
    }

    private function parseIpv4Part(string $part): ?int
    {
        if (str_starts_with($part, '0x')) {
            $hex = substr($part, 2);

            if ($hex === '' || strlen($hex) > 8 || preg_match('/\A[0-9a-f]+\z/', $hex) !== 1) {
                return null;
            }

            $value = hexdec($hex);

            return is_int($value) ? $value : null;
        }

        if (preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $part) !== 1) {
            return null;
        }

        $value = (int) $part;

        return (string) $value === $part ? $value : null;
    }

    private function detectKind(string $mimeType, string $filename): ?AttachmentKind
    {
        $mimeKind = self::MIME_KINDS[$mimeType] ?? null;
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $extensionKind = self::EXTENSION_KINDS[$extension] ?? null;

        if ($mimeKind === null && $extensionKind === null) {
            return null;
        }

        if ($mimeType !== '' && $extension !== '' && $extensionKind !== null) {
            $expectedMime = self::EXTENSION_MIME_TYPES[$extension] ?? null;

            if ($expectedMime !== null && ! $this->mimeMatchesExtension($mimeType, $expectedMime, $extensionKind, $extension)) {
                return null;
            }
        }

        if ($mimeKind !== null && $extensionKind !== null && $mimeKind !== $extensionKind) {
            return null;
        }

        $kind = $mimeKind ?? $extensionKind;

        return $kind === null ? null : AttachmentKind::from($kind);
    }

    private function normalizedMimeType(string $mimeType, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $expected = self::EXTENSION_MIME_TYPES[$extension] ?? null;

        // Office files are ZIP containers to fileinfo. Advertise the
        // extension MIME so providers preserve the recipient's filename.
        if ($expected !== null && ($mimeType === ''
            || $mimeType === 'application/octet-stream'
            || ($mimeType === 'application/zip' && in_array($extension, ['docx', 'xlsx', 'pptx'], true)))) {
            return $expected;
        }

        return $mimeType;
    }

    private function mimeMatchesExtension(
        string $mimeType,
        string $expectedMime,
        ?string $extensionKind,
        string $extension,
    ): bool {
        $actual = strtolower(trim(explode(';', $mimeType, 2)[0]));
        $expected = strtolower(trim(explode(';', $expectedMime, 2)[0]));

        if ($actual === $expected) {
            return true;
        }

        // A few clients report generic content types for document uploads;
        // the extension still identifies the supported document container.
        if ($extensionKind === 'document' && $actual === 'application/octet-stream') {
            return true;
        }

        // OOXML documents are ZIP containers when inspected by fileinfo.
        return $extensionKind === 'document'
            && $actual === 'application/zip'
            && in_array($extension, ['docx', 'xlsx', 'pptx'], true);
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim($filename);
        $filename = preg_replace('/[^A-Za-z0-9._ -]+/u', '_', $filename) ?: 'attachment';
        $filename = trim($filename, ' ._');

        return mb_substr($filename !== '' ? $filename : 'attachment', 0, 255);
    }
}
