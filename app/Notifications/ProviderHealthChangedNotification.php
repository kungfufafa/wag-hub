<?php

namespace App\Notifications;

use App\Filament\Resources\ProviderAccounts\ProviderAccountResource;
use App\Models\AlertSetting;
use App\Models\ProviderAccount;
use App\Services\Alerts\AlertMailerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

final class ProviderHealthChangedNotification extends Notification
{
    use Queueable;

    /**
     * @param  'mail'|'telegram'  $channel
     */
    public function __construct(
        public readonly string $channel,
        public readonly ?int $providerAccountId,
        public readonly string $fromStatus,
        public readonly string $toStatus,
        public readonly int $consecutiveFailures,
        public readonly ?string $circuitOpenUntilIso,
        public readonly ?string $errorSummary,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return [$this->channel];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $settings = AlertSetting::current();
        $body = $this->buildBody();

        return (new MailMessage)
            ->mailer(AlertMailerFactory::MAILER_NAME)
            ->from(
                (string) $settings->smtp_from_address,
                (string) ($settings->smtp_from_name ?: 'WhatsApp Gateway Hub'),
            )
            ->subject($this->subjectLine())
            ->line($body);
    }

    /**
     * @return array{text: string, parse_mode: null}
     */
    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => $this->buildBody(),
            'parse_mode' => null,
        ];
    }

    private function subjectLine(): string
    {
        return sprintf(
            'Provider %s',
            strtoupper($this->toStatus),
        );
    }

    private function buildBody(): string
    {
        $provider = $this->providerAccountId !== null
            ? ProviderAccount::query()->find($this->providerAccountId)
            : null;

        $marker = match ($this->toStatus) {
            'unavailable' => '🔴',
            'degraded' => '🟡',
            'healthy' => '🟢',
            default => '⚪',
        };

        $lines = [
            sprintf('%s Provider %s', $marker, strtoupper($this->toStatus)),
            'Nama: '.($provider?->name ?? '—'),
            'Driver: '.($provider?->driver ?? '—'),
            sprintf('Status: %s → %s', $this->fromStatus, $this->toStatus),
            'Kegagalan beruntun: '.$this->consecutiveFailures,
            'Circuit open sampai: '.($this->circuitOpenUntilIso ?: '—'),
            'Error terakhir: '.($this->errorSummary ?: '—'),
        ];

        if ($provider !== null) {
            $lines[] = 'UUID: '.$provider->uuid;
            $deepLink = $this->deepLink($provider);

            if ($deepLink !== null) {
                $lines[] = 'Telusuri: '.$deepLink;
            }
        }

        return implode("\n", $lines);
    }

    private function deepLink(ProviderAccount $provider): ?string
    {
        try {
            return ProviderAccountResource::getUrl(
                'edit',
                ['record' => $provider],
                panel: 'admin',
            );
        } catch (Throwable) {
            return null;
        }
    }
}
