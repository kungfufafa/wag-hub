<?php

namespace App\Notifications\Channels;

use App\Models\AlertSetting;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class TelegramChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toTelegram')) {
            return;
        }

        /** @var array{text?: string, parse_mode?: string|null} $payload */
        $payload = $notification->toTelegram($notifiable);
        $text = (string) ($payload['text'] ?? '');

        if ($text === '') {
            throw new RuntimeException('Telegram notification text is empty.');
        }

        $chatId = $notifiable->routeNotificationFor('telegram', $notification);

        if (! filled($chatId)) {
            throw new RuntimeException('Telegram chat ID is missing.');
        }

        $token = AlertSetting::current()->telegram_bot_token;

        if (! filled($token)) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }

        $response = Http::timeout(15)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => (string) $chatId,
                'text' => $text,
            ],
        );

        if ($response->failed() || ! ($response->json('ok') === true)) {
            $description = (string) ($response->json('description') ?: $response->body() ?: 'Telegram API error');

            throw new RuntimeException('Telegram send failed: '.$description);
        }
    }
}
