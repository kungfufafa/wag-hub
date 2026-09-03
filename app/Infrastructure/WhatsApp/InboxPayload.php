<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Inbox\InboxEvent;

final class InboxPayload
{
    public static function string(mixed $value): ?string
    {
        if (! is_scalar($value) || is_bool($value) || trim((string) $value) === '') {
            return null;
        }

        return trim((string) $value);
    }

    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    public static function unix(mixed $value): ?int
    {
        if (is_numeric($value)) {
            $unix = (int) $value;

            if ($unix > 10_000_000_000) {
                $unix = (int) floor($unix / 1000);
            }

            return $unix >= 1_000_000_000 ? $unix : null;
        }

        if (is_string($value) && $value !== '') {
            $parsed = strtotime($value);

            return $parsed !== false ? $parsed : null;
        }

        return null;
    }

    public static function displayTime(?int $unix): ?string
    {
        return $unix === null ? null : date('d M H.i', $unix);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(mixed $payload): array
    {
        if (! is_array($payload) || $payload === []) {
            return [];
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        foreach (['messages', 'chats', 'data', 'items'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return self::rows($payload[$key]);
            }
        }

        if (isset($payload['results']) && is_array($payload['results'])) {
            return self::rows($payload['results']);
        }

        return [$payload];
    }

    public static function peerKey(string $chatId): string
    {
        if (str_contains($chatId, '@g.us') || str_contains($chatId, '@broadcast')) {
            return $chatId;
        }

        $digits = preg_replace('/\D+/', '', explode('@', $chatId)[0] ?? '') ?? '';

        return $digits !== '' ? $digits : $chatId;
    }

    public static function isGroup(string $chatId): bool
    {
        return str_contains($chatId, '@g.us');
    }

    public static function displayName(string $chatId): string
    {
        return explode('@', $chatId)[0] ?: $chatId;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromMe(array $row): bool
    {
        foreach ([
            $row['fromMe'] ?? null,
            $row['from_me'] ?? null,
            $row['is_from_me'] ?? null,
            $row['isFromMe'] ?? null,
            data_get($row, 'key.fromMe'),
            data_get($row, 'id.fromMe'),
            data_get($row, '_data.id.fromMe'),
            data_get($row, '_data.key.fromMe'),
            data_get($row, 'message.fromMe'),
        ] as $candidate) {
            $parsed = self::bool($candidate);

            if ($parsed !== null) {
                return $parsed;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function body(array $row): ?string
    {
        $candidates = [
            $row['body'] ?? null,
            $row['caption'] ?? null,
            $row['text'] ?? null,
            $row['content'] ?? null,
            $row['message'] ?? null,
            $row['last_message'] ?? null,
            $row['preview'] ?? null,
            data_get($row, 'message.conversation'),
            data_get($row, 'message.extendedTextMessage.text'),
            data_get($row, 'message.imageMessage.caption'),
            data_get($row, 'message.videoMessage.caption'),
            data_get($row, 'message.text'),
            data_get($row, '_data.body'),
            data_get($row, '_data.message.conversation'),
            data_get($row, 'lastMessage.body'),
            data_get($row, 'lastMessage.caption'),
        ];

        foreach ($candidates as $candidate) {
            $text = self::string($candidate);

            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function hasMedia(array $row): bool
    {
        return self::bool($row['hasMedia'] ?? $row['has_media'] ?? null) === true
            || self::string($row['media_type'] ?? $row['mediaType'] ?? null) !== null
            || (is_array($row['message'] ?? null) && array_intersect_key(
                $row['message'],
                array_flip(['imageMessage', 'videoMessage', 'audioMessage', 'documentMessage', 'stickerMessage']),
            ) !== []);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function chatId(array $row, bool $fromMe): ?string
    {
        $candidates = [
            $row['chatId'] ?? null,
            $row['chat_id'] ?? null,
            $row['chat_jid'] ?? null,
            $row['jid'] ?? null,
            data_get($row, 'key.remoteJid'),
            data_get($row, 'id.remote'),
            $fromMe ? ($row['to'] ?? null) : ($row['from'] ?? null),
            $row['from'] ?? null,
            $row['to'] ?? null,
            $row['sender'] ?? null,
            $row['sender_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = self::string($candidate);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function title(array $row, string $chatId): string
    {
        return self::string(
            $row['name']
            ?? $row['pushName']
            ?? $row['pushname']
            ?? $row['notifyName']
            ?? $row['subject']
            ?? $row['notify']
            ?? null,
        ) ?? self::displayName($chatId);
    }

    private static function looksLikeChatJid(string $value): bool
    {
        return (bool) preg_match('/^.+@(c\.us|s\.whatsapp\.net|g\.us|lid|broadcast)$/', $value)
            && ! str_contains($value, '_');
    }

    public static function stringifyId(mixed $id): ?string
    {
        if (is_bool($id) || $id === null) {
            return null;
        }

        if (is_scalar($id) && trim((string) $id) !== '') {
            return trim((string) $id);
        }

        if (! is_array($id)) {
            return null;
        }

        foreach (['_serialized', 'id', '_serialized_id'] as $key) {
            $value = self::string($id[$key] ?? null);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function messageId(array $row, string $chatId, bool $fromMe, string $body, int $occurredAt): string
    {
        $candidates = [
            $row['id'] ?? null,
            $row['message_id'] ?? null,
            $row['messageId'] ?? null,
            data_get($row, 'key.id'),
            data_get($row, 'id.id'),
            data_get($row, 'id._serialized'),
            data_get($row, '_data.id'),
            data_get($row, 'message.id'),
        ];

        foreach ($candidates as $candidate) {
            $resolved = self::stringifyId($candidate);

            if ($resolved !== null && ! self::looksLikeChatJid($resolved)) {
                return mb_substr($resolved, 0, 190);
            }
        }

        return hash('sha1', $chatId.'|'.($fromMe ? '1' : '0').'|'.$body.'|'.$occurredAt);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function eventFromWahaRow(array $row): ?InboxEvent
    {
        $fromMe = self::fromMe($row);
        $chatId = self::chatId($row, $fromMe);

        if ($chatId === null) {
            return null;
        }

        $hasMedia = self::hasMedia($row);
        $body = self::body($row);

        if ($body === null && $hasMedia) {
            $body = '[Media]';
        }

        if ($body === null || $body === '') {
            return null;
        }

        $occurredAt = self::unix(
            $row['timestamp']
            ?? $row['messageTimestamp']
            ?? data_get($row, '_data.t')
            ?? null,
        ) ?? time();

        return new InboxEvent(
            chatId: $chatId,
            messageId: self::messageId($row, $chatId, $fromMe, $body, $occurredAt),
            body: $body,
            fromMe: $fromMe,
            occurredAt: $occurredAt,
            kind: $hasMedia ? 'media' : 'text',
            title: self::title($row, $chatId),
            isGroup: self::isGroup($chatId),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function eventFromGowaRow(array $row): ?InboxEvent
    {
        $fromMe = self::fromMe($row);
        $chatId = self::chatId($row, $fromMe);

        if ($chatId === null) {
            return null;
        }

        $media = self::string($row['media_type'] ?? $row['mediaType'] ?? null);
        $body = self::body($row);

        if ($body === null && $media !== null) {
            $body = '['.ucfirst($media).']';
        }

        if ($body === null || $body === '') {
            return null;
        }

        $occurredAt = self::unix(
            $row['timestamp']
            ?? $row['created_at']
            ?? $row['last_message_time']
            ?? $row['updated_at']
            ?? null,
        ) ?? time();

        return new InboxEvent(
            chatId: $chatId,
            messageId: self::messageId($row, $chatId, $fromMe, $body, $occurredAt),
            body: $body,
            fromMe: $fromMe,
            occurredAt: $occurredAt,
            kind: $media !== null ? 'media' : 'text',
            title: self::title($row, $chatId),
            isGroup: self::isGroup($chatId),
        );
    }
}
