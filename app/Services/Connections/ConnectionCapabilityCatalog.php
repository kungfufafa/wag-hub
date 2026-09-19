<?php

namespace App\Services\Connections;

use App\Domain\Connections\ConnectionCapability;

final class ConnectionCapabilityCatalog
{
    /**
     * @return list<string>
     */
    public function forDriver(string $driver): array
    {
        return match (strtolower(trim($driver))) {
            'wag_hub' => [
                ConnectionCapability::SendText->value,
                ConnectionCapability::NumberLookup->value,
                ConnectionCapability::DeliveryStatus->value,
            ],
            'waha', 'fonnte', 'gowa' => [
                ConnectionCapability::SendText->value,
                ConnectionCapability::SendImage->value,
                ConnectionCapability::SendDocument->value,
                ConnectionCapability::SendVideo->value,
                ConnectionCapability::SendAudio->value,
                ConnectionCapability::NumberLookup->value,
                ConnectionCapability::InboundMessages->value,
                ConnectionCapability::DeliveryStatus->value,
            ],
            'waba' => [
                ConnectionCapability::SendText->value,
                ConnectionCapability::SendImage->value,
                ConnectionCapability::SendDocument->value,
                ConnectionCapability::SendVideo->value,
                ConnectionCapability::SendAudio->value,
                ConnectionCapability::InboundMessages->value,
                ConnectionCapability::DeliveryStatus->value,
            ],
            default => [],
        };
    }

    /**
     * @param  list<string>  $drivers
     * @return list<string>
     */
    public function union(array $drivers): array
    {
        $capabilities = [];

        foreach ($drivers as $driver) {
            foreach ($this->forDriver((string) $driver) as $capability) {
                $capabilities[$capability] = true;
            }
        }

        return array_keys($capabilities);
    }

    /**
     * @param  list<string>  $capabilities
     */
    public function supports(array $capabilities, string $messageType): bool
    {
        $required = $this->forMessageType($messageType);

        return $required !== null && in_array($required->value, $capabilities, true);
    }

    public function forMessageType(string $type): ?ConnectionCapability
    {
        return ConnectionCapability::forMessageType($type);
    }
}
