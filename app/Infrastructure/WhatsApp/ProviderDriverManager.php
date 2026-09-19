<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Contracts\WhatsApp\ProviderNumberChecker;
use App\Domain\Delivery\OutboundMessage;
use App\Domain\Delivery\ProviderResult;
use App\Domain\NumberCheck\NumberCheckResult;
use App\Models\ProviderAccount;

final readonly class ProviderDriverManager
{
    public function __construct(
        private WahaDriver $waha,
        private WagHubDriver $wagHub,
        private FonnteDriver $fonnte,
        private GowaDriver $gowa,
        private WabaDriver $waba,
    ) {}

    public function send(ProviderAccount $account, OutboundMessage $message): ProviderResult
    {
        $driver = $this->resolve((string) $account->driver);

        if ($driver === null) {
            return ProviderResult::providerFailed(
                errorCode: 'provider_driver_unsupported',
                errorMessage: 'Driver provider tidak didukung.',
            );
        }

        return $driver->send($account, $message);
    }

    public function resolve(string $driver): ?ProviderDriver
    {
        return match (strtolower(trim($driver))) {
            'wag_hub' => $this->wagHub,
            'waha' => $this->waha,
            'fonnte' => $this->fonnte,
            'gowa' => $this->gowa,
            'waba' => $this->waba,
            default => null,
        };
    }

    public function checkNumber(ProviderAccount $account, string $recipient): NumberCheckResult
    {
        $driver = $this->resolve((string) $account->driver);

        if (! $driver instanceof ProviderNumberChecker) {
            return NumberCheckResult::unsupported();
        }

        return $driver->checkNumber($account, $recipient);
    }
}
