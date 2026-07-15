<?php

namespace App\Infrastructure\WhatsApp;

use InvalidArgumentException;

final class ProviderEndpointGuard
{
    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata.google',
        'metadata.google.internal',
        'instance-data.ec2.internal',
    ];

    public function assertAllowed(string $endpoint): void
    {
        $parts = parse_url(trim($endpoint));

        if (! is_array($parts)) {
            throw $this->notAllowed();
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw $this->notAllowed();
        }

        $ipCandidate = trim($host, '[]');

        if ($this->isBlockedHost($host) || $this->isBlockedIp($ipCandidate)) {
            throw $this->notAllowed();
        }

        $httpsHosts = $this->configuredHosts('gateway.provider_endpoints.https_hosts');
        $httpHosts = $this->configuredHosts('gateway.provider_endpoints.http_hosts');
        $allowedHosts = $scheme === 'https'
            ? array_values(array_unique([...$httpsHosts, ...$httpHosts]))
            : $httpHosts;

        if (! in_array($host, $allowedHosts, true)) {
            throw $this->notAllowed();
        }
    }

    private function isBlockedHost(string $host): bool
    {
        return in_array($host, self::BLOCKED_HOSTS, true)
            || str_ends_with($host, '.localhost');
    }

    private function isBlockedIp(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    /**
     * @return list<string>
     */
    private function configuredHosts(string $key): array
    {
        $hosts = config($key, []);

        if (! is_array($hosts)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $host): string => strtolower(trim((string) $host)),
            $hosts,
        ))));
    }

    private function notAllowed(): InvalidArgumentException
    {
        return new InvalidArgumentException('Provider endpoint is not allowed.');
    }
}
