<?php

namespace App\Services\Engine;

use App\Exceptions\WhatsAppEngineException;
use Symfony\Component\HttpFoundation\IpUtils;

/** Validate again at delivery time and pin DNS for that connection. */
class WebhookDestination
{
    public function options(string $url): array
    {
        if (! extension_loaded('curl')) {
            throw new WhatsAppEngineException('Webhook delivery requires the cURL extension for DNS pinning.', 503, 'webhook_transport_unavailable');
        }
        $parts = parse_url($url);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new WhatsAppEngineException('Webhook URL must be HTTPS without credentials or fragments.', 422, 'invalid_webhook_url');
        }

        $allowed = in_array($host, (array) config('engine_v2.webhook_allow_private_hosts', []), true);
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($addresses === [] && function_exists('dns_get_record')) {
            foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }
        if ($addresses === []) {
            throw new WhatsAppEngineException('Webhook hostname cannot be resolved.', 422, 'webhook_dns_failed');
        }
        foreach ($addresses as $address) {
            if (! $allowed && (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)
                || IpUtils::checkIp($address, ['224.0.0.0/4', 'ff00::/8']))) {
                throw new WhatsAppEngineException('Webhook destination is not allowed.', 422, 'webhook_destination_blocked');
            }
        }
        $address = $addresses[0];
        $port = (int) ($parts['port'] ?? 443);

        // An environment proxy would resolve the host independently and defeat DNS pinning.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ['proxy' => ''];
        }

        return ['proxy' => '', 'curl' => [10203 => [$host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address)]]];
    }

    public function assertAllowed(string $url): void
    {
        $this->options($url);
    }
}
