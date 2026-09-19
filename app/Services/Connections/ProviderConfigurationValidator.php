<?php

namespace App\Services\Connections;

use App\Exceptions\WhatsAppConnectionException;
use App\Infrastructure\WhatsApp\ProviderEndpointGuard;
use Illuminate\Support\Arr;
use InvalidArgumentException;

final class ProviderConfigurationValidator
{
    public function __construct(private ProviderEndpointGuard $endpoints) {}

    /**
     * @return list<string>
     */
    public function allowedKeys(string $driver): array
    {
        return match ($driver) {
            'wag_hub' => [],
            'waha' => ['base_url', 'session', 'api_key', 'webhook_secret'],
            'fonnte' => ['endpoint', 'validate_endpoint', 'token', 'attachment_max_bytes', 'webhook_secret'],
            'gowa' => ['base_url', 'username', 'password', 'device_id', 'version', 'webhook_secret'],
            'waba' => ['base_url', 'api_version', 'phone_number_id', 'access_token', 'webhook_secret'],
            default => [],
        };
    }

    public function secretKey(string $driver): ?string
    {
        return match ($driver) {
            'waha' => 'api_key',
            'fonnte' => 'token',
            'gowa' => 'password',
            'waba' => 'access_token',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function validate(string $driver, array $configuration, array $existing = []): array
    {
        if (! in_array($driver, ['wag_hub', 'waha', 'fonnte', 'gowa', 'waba'], true)) {
            throw new WhatsAppConnectionException(
                'Provider is not supported.',
                422,
                'capability_not_supported',
            );
        }

        $allowed = $this->allowedKeys($driver);
        $incoming = array_filter(
            Arr::only($configuration, $allowed),
            fn (mixed $value): bool => filled($value),
        );
        $merged = array_replace(Arr::only($existing, $allowed), $incoming);

        if ($driver === 'fonnte' && blank($merged['endpoint'] ?? null)) {
            $merged['endpoint'] = 'https://api.fonnte.com/send';
        }

        if ($driver === 'waba' && blank($merged['base_url'] ?? null)) {
            $merged['base_url'] = 'https://graph.facebook.com';
        }

        $secret = $this->secretKey($driver);

        if ($driver !== 'wag_hub' && ($secret === null || blank($merged[$secret] ?? null))) {
            throw new WhatsAppConnectionException(
                'Provider credentials are incomplete.',
                422,
                'connection_not_ready',
            );
        }

        foreach (['base_url', 'endpoint', 'validate_endpoint'] as $urlKey) {
            if (! is_string($merged[$urlKey] ?? null) || trim((string) $merged[$urlKey]) === '') {
                continue;
            }

            try {
                $this->endpoints->assertAllowed((string) $merged[$urlKey]);
            } catch (InvalidArgumentException) {
                throw new WhatsAppConnectionException(
                    'Provider endpoint is not allowed.',
                    422,
                    'connection_not_ready',
                );
            }
        }

        if ($driver === 'waha' && blank($merged['session'] ?? null)) {
            throw new WhatsAppConnectionException(
                'WAHA session name is required.',
                422,
                'connection_not_ready',
            );
        }

        if ($driver === 'waba' && blank($merged['phone_number_id'] ?? null)) {
            throw new WhatsAppConnectionException(
                'WABA phone number ID is required.',
                422,
                'connection_not_ready',
            );
        }

        if ($driver === 'gowa' && blank($merged['username'] ?? null)) {
            throw new WhatsAppConnectionException(
                'GOWA username is required.',
                422,
                'connection_not_ready',
            );
        }

        return $merged;
    }
}
