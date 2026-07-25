<?php

namespace App\Services\Alerts;

use App\Models\AlertSetting;

final class AlertMailerFactory
{
    public const MAILER_NAME = 'provider_health_alerts';

    public function registerFromSettings(AlertSetting $settings): void
    {
        config([
            'mail.mailers.'.self::MAILER_NAME => [
                'transport' => 'smtp',
                'host' => $settings->smtp_host,
                'port' => $settings->smtp_port,
                'encryption' => $settings->smtp_encryption,
                'username' => $settings->smtp_username,
                'password' => $settings->smtp_password,
                'timeout' => 30,
            ],
        ]);

        app('mail.manager')->purge(self::MAILER_NAME);
    }

    public function isConfigured(AlertSetting $settings): bool
    {
        return filled($settings->smtp_host)
            && filled($settings->smtp_port)
            && filled($settings->smtp_from_address);
    }
}
