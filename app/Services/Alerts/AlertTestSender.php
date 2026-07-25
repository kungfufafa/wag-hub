<?php

namespace App\Services\Alerts;

use App\Models\AlertSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

final class AlertTestSender
{
    public const TEST_MESSAGE = 'WAG-Hub alert test';

    public function __construct(
        private readonly AlertMailerFactory $mailerFactory,
    ) {}

    public function sendEmail(string $destination): void
    {
        $settings = AlertSetting::current();

        if (! $this->mailerFactory->isConfigured($settings)) {
            throw new RuntimeException('SMTP belum dikonfigurasi lengkap (host, port, from address).');
        }

        $this->mailerFactory->registerFromSettings($settings);

        Mail::mailer(AlertMailerFactory::MAILER_NAME)
            ->raw(self::TEST_MESSAGE, function ($message) use ($destination, $settings): void {
                $message
                    ->to($destination)
                    ->subject('WAG-Hub alert test')
                    ->from(
                        (string) $settings->smtp_from_address,
                        (string) ($settings->smtp_from_name ?: 'WhatsApp Gateway Hub'),
                    );
            });
    }

    public function sendTelegram(string $chatId): void
    {
        $settings = AlertSetting::current();
        $token = $settings->telegram_bot_token;

        if (! filled($token)) {
            throw new RuntimeException('Telegram bot token belum dikonfigurasi.');
        }

        $response = Http::timeout(15)->post(
            "https://api.telegram.org/bot{$token}/sendMessage",
            [
                'chat_id' => $chatId,
                'text' => self::TEST_MESSAGE,
            ],
        );

        if ($response->failed() || ! ($response->json('ok') === true)) {
            $description = (string) ($response->json('description') ?: $response->body() ?: 'Telegram API error');

            throw new RuntimeException('Telegram send failed: '.$description);
        }
    }
}
