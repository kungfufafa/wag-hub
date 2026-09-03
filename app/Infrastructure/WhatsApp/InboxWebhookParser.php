<?php

namespace App\Infrastructure\WhatsApp;

use App\Domain\Inbox\InboxEvent;

final class InboxWebhookParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboxEvent>
     */
    public function parse(string $driver, array $payload): array
    {
        return match ($driver) {
            'waha' => $this->waha($payload),
            'gowa' => $this->gowa($payload),
            'fonnte' => $this->fonnte($payload),
            'waba' => $this->waba($payload),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboxEvent>
     */
    private function waha(array $payload): array
    {
        $eventName = strtolower((string) InboxPayload::string($payload['event'] ?? $payload['type'] ?? null));

        if ($eventName !== '' && (! str_starts_with($eventName, 'message') || str_contains($eventName, 'ack'))) {
            return [];
        }

        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;
        $events = [];

        foreach (InboxPayload::rows($inner) as $row) {
            if (isset($row['event']) && is_array($row['payload'] ?? null)) {
                $events = [...$events, ...$this->waha($row)];

                continue;
            }

            $event = InboxPayload::eventFromWahaRow($row);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboxEvent>
     */
    private function gowa(array $payload): array
    {
        $inner = is_array($payload['payload'] ?? null) ? $payload['payload'] : $payload;

        if (isset($inner['message']) && is_array($inner['message'])) {
            $event = InboxPayload::eventFromGowaRow(array_merge($inner, $inner['message']));

            return $event !== null ? [$event] : [];
        }

        $events = [];

        foreach (InboxPayload::rows($inner) as $row) {
            $event = InboxPayload::eventFromGowaRow($row);

            if ($event !== null) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboxEvent>
     */
    private function fonnte(array $payload): array
    {
        $sender = InboxPayload::string($payload['sender'] ?? $payload['from'] ?? null);
        $body = InboxPayload::string($payload['message'] ?? $payload['text'] ?? $payload['button'] ?? null);

        if ($sender === null || $body === null) {
            return [];
        }

        $occurredAt = InboxPayload::unix($payload['timestamp'] ?? $payload['time'] ?? null) ?? time();
        $fromMe = InboxPayload::fromMe($payload);
        $isGroup = InboxPayload::string($payload['member'] ?? null) !== null;
        $name = InboxPayload::string($payload['name'] ?? $payload['group'] ?? null);

        return [
            new InboxEvent(
                chatId: $sender,
                messageId: InboxPayload::string($payload['id'] ?? $payload['messageid'] ?? null)
                    ?? hash('sha1', $sender.'|'.($fromMe ? '1' : '0').'|'.$body.'|'.$occurredAt),
                body: $body,
                fromMe: $fromMe,
                occurredAt: $occurredAt,
                title: $name ?? InboxPayload::displayName($sender),
                isGroup: $isGroup,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<InboxEvent>
     */
    private function waba(array $payload): array
    {
        $events = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            foreach ($entry['changes'] ?? [] as $change) {
                $value = is_array($change) ? ($change['value'] ?? []) : [];

                if (! is_array($value)) {
                    continue;
                }

                $names = [];

                foreach ($value['contacts'] ?? [] as $contact) {
                    if (! is_array($contact)) {
                        continue;
                    }

                    $waId = InboxPayload::string($contact['wa_id'] ?? null);

                    if ($waId !== null) {
                        $names[$waId] = InboxPayload::string(data_get($contact, 'profile.name'));
                    }
                }

                foreach ($value['messages'] ?? [] as $message) {
                    if (! is_array($message)) {
                        continue;
                    }

                    $from = InboxPayload::string($message['from'] ?? null);

                    if ($from === null) {
                        continue;
                    }

                    $type = InboxPayload::string($message['type'] ?? 'text') ?? 'text';
                    $body = match ($type) {
                        'text' => InboxPayload::string(data_get($message, 'text.body')),
                        'button' => InboxPayload::string(data_get($message, 'button.text')),
                        'interactive' => InboxPayload::string(data_get($message, 'interactive.button_reply.title'))
                            ?? InboxPayload::string(data_get($message, 'interactive.list_reply.title')),
                        'image', 'video', 'audio', 'document', 'sticker' => '['.ucfirst($type).']',
                        default => InboxPayload::string(data_get($message, 'text.body')),
                    };

                    if ($body === null) {
                        continue;
                    }

                    $occurredAt = InboxPayload::unix($message['timestamp'] ?? null) ?? time();
                    $isMedia = in_array($type, ['image', 'video', 'audio', 'document', 'sticker'], true);

                    $events[] = new InboxEvent(
                        chatId: $from,
                        messageId: InboxPayload::string($message['id'] ?? null)
                            ?? hash('sha1', $from.'|0|'.$body.'|'.$occurredAt),
                        body: $body,
                        fromMe: false,
                        occurredAt: $occurredAt,
                        kind: $isMedia ? 'media' : 'text',
                        title: $names[$from] ?? $from,
                    );
                }
            }
        }

        return $events;
    }
}
