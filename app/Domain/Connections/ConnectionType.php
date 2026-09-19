<?php

namespace App\Domain\Connections;

enum ConnectionType: string
{
    case ManagedNumber = 'managed_number';
    case ProviderRoute = 'provider_route';

    public function label(): string
    {
        return match ($this) {
            self::ManagedNumber => 'Nomor WhatsApp',
            self::ProviderRoute => 'Provider',
        };
    }

    public function pinsSender(): bool
    {
        return $this === self::ManagedNumber;
    }
}
