<?php

namespace App\Services\Connection;

use App\Domain\Connection\ConnectionCapability;
use App\Models\ProviderAccount;
use App\Models\WhatsAppConnection;

final class ConnectionCapabilityResolver
{
    /**
     * @return list<string>
     */
    public function resolve(WhatsAppConnection $connection, ?ProviderAccount $provider = null): array
    {
        $provider ??= $connection->providerAccount;

        if ($provider === null) {
            return [ConnectionCapability::SendText->value];
        }

        $capabilities = [
            ConnectionCapability::SendText->value,
            ConnectionCapability::DeliveryStatus->value,
        ];

        if (in_array($provider->driver, ['wag_hub', 'waha', 'fonnte', 'gowa'], true)) {
            $capabilities[] = ConnectionCapability::NumberLookup->value;
        }

        if (in_array($provider->driver, ['waha', 'fonnte', 'gowa', 'wag_hub'], true)) {
            $capabilities[] = ConnectionCapability::SendImage->value;
            $capabilities[] = ConnectionCapability::SendDocument->value;
            $capabilities[] = ConnectionCapability::SendVideo->value;
            $capabilities[] = ConnectionCapability::SendAudio->value;
        }

        if (in_array($provider->driver, ['wag_hub', 'waha', 'fonnte', 'gowa'], true)) {
            $capabilities[] = ConnectionCapability::InboundMessages->value;
        }

        return array_values(array_unique($capabilities));
    }

    public function supports(WhatsAppConnection $connection, ConnectionCapability $capability): bool
    {
        $capabilities = $connection->capabilities;

        if (! is_array($capabilities) || $capabilities === []) {
            $capabilities = $this->resolve($connection);
        }

        return in_array($capability->value, $capabilities, true);
    }
}
