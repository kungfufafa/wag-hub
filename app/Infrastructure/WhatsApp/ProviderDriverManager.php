<?php

namespace App\Infrastructure\WhatsApp;

use App\Contracts\WhatsApp\ProviderDriver;
use App\Domain\Delivery\OutboundText;
use App\Domain\Delivery\ProviderResult;
use App\Models\ProviderAccount;

final readonly class ProviderDriverManager
{
    public function __construct(
        private WahaDriver $waha,
        private FonnteDriver $fonnte,
    ) {}

    public function send(ProviderAccount $account, OutboundText $message): ProviderResult
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
            'waha' => $this->waha,
            'fonnte' => $this->fonnte,
            default => null,
        };
    }
}
