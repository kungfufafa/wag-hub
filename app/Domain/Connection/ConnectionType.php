<?php

namespace App\Domain\Connection;

enum ConnectionType: string
{
    case ManagedNumber = 'managed_number';
    case ProviderRoute = 'provider_route';

    public function label(): string
    {
        return match ($this) {
            self::ManagedNumber => 'Nomor WhatsApp terkelola',
            self::ProviderRoute => 'Provider eksternal',
        };
    }

    public function pinsSender(): bool
    {
        return $this === self::ManagedNumber;
    }
}
