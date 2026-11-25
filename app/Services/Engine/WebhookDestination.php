<?php

namespace App\Services\Engine;

use App\Exceptions\WhatsAppEngineException;
use App\Infrastructure\WhatsApp\HostResolver;
use App\Infrastructure\WhatsApp\ProviderEndpointGuard;
use InvalidArgumentException;

/** Validate again at delivery time and pin DNS for that connection. */
class WebhookDestination
{
    public function __construct(private ProviderEndpointGuard $guard, private HostResolver $resolver) {}

    public function options(string $url): array
    {
        if (! extension_loaded('curl')) {
            throw new WhatsAppEngineException('Webhook delivery requires the cURL extension for DNS pinning.', 503, 'webhook_transport_unavailable');
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower(trim((string) ($parts['host'] ?? ''), '[]')) : '';
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new WhatsAppEngineException('Webhook URL must be HTTPS without credentials or fragments.', 422, 'invalid_webhook_url');
        }

        if (! in_array($host, (array) config('engine_v2.webhook_allow_private_hosts', []), true)) {
            try {
                return $this->guard->assertPublic($url);
            } catch (InvalidArgumentException) {
                if (filter_var($host, FILTER_VALIDATE_IP) === false && ! str_ends_with($host, '.') && $this->resolver->resolve($host) === []) {
                    throw new WhatsAppEngineException('Webhook hostname cannot be resolved.', 422, 'webhook_dns_failed');
                }
                throw new WhatsAppEngineException('Webhook destination is not allowed.', 422, 'webhook_destination_blocked');
            }
        }

        return $this->allowlistedOptions($host, (int) ($parts['port'] ?? 443));
    }

    public function assertAllowed(string $url): void
    {
        $this->options($url);
    }

    /** Operator-allowlisted private hosts skip the public-range checks but are still DNS-pinned. */
    private function allowlistedOptions(string $host, int $port): array
    {
        // An environment proxy would resolve the host independently and defeat DNS pinning.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['proxy' => '', 'allow_redirects' => false];
        }

        // curl ignores a CURLOPT_RESOLVE pin for "name" when the URL says "name.".
        if (str_ends_with($host, '.')) {
            throw new WhatsAppEngineException('Webhook destination is not allowed.', 422, 'webhook_destination_blocked');
        }

        $addresses = gethostbynamel($host) ?: [];
        if ($addresses === [] && function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            throw new WhatsAppEngineException('Webhook hostname cannot be resolved.', 422, 'webhook_dns_failed');
        }
        $address = $addresses[0];

        return ['proxy' => '', 'allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]]];
    }
}
