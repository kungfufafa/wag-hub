<?php

namespace App\Infrastructure\WhatsApp;

use App\Models\ProviderAccount;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\IpUtils;

final class ProviderEndpointGuard
{
    private const BLOCKED_HOSTS = [
        'localhost',
        'metadata.google',
        'metadata.google.internal',
        'instance-data.ec2.internal',
    ];

    /** Ranges FILTER_FLAG_GLOBAL_RANGE lets through but that must not be reachable from tenant input. */
    private const BLOCKED_PUBLIC_RANGES = [
        '224.0.0.0/4',
        'ff00::/8',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '2002::/16',
    ];

    public function __construct(private HostResolver $resolver) {}

    /**
     * Admin-configured accounts keep the literal-host checks. Accounts whose
     * endpoints come from a tenant must resolve to public addresses only; the
     * returned HTTP options pin the connection to the address that was checked.
     *
     * @return array<string, mixed>
     */
    public function assertAllowed(string $endpoint, ?ProviderAccount $account = null): array
    {
        if ($account?->hasTenantSuppliedEndpoints()) {
            return $this->assertPublic($endpoint);
        }

        $this->parse($endpoint);

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function assertPublic(string $endpoint): array
    {
        [$parts, $host] = $this->parse($endpoint);

        // curl ignores a CURLOPT_RESOLVE pin for "name" when the URL says "name.", so the
        // checked address would not be the one connected to. Fully-qualified forms are refused.
        if (str_ends_with($host, '.')) {
            throw $this->notAllowed();
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } elseif ($this->looksNumeric($host)) {
            throw $this->notAllowed();
        } else {
            $addresses = $this->resolver->resolve($host);
        }

        if ($addresses === []) {
            throw $this->notAllowed();
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                throw $this->notAllowed();
            }
        }

        $options = ['proxy' => '', 'allow_redirects' => false];

        if (filter_var($host, FILTER_VALIDATE_IP) === false && defined('CURLOPT_RESOLVE')) {
            $port = (int) ($parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80));
            $address = $addresses[0];
            $options['curl'] = [CURLOPT_RESOLVE => [
                $host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address),
            ]];
        }

        return $options;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function parse(string $endpoint): array
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

        return [$parts, $ipCandidate];
    }

    private function isBlockedHost(string $host): bool
    {
        $host = rtrim($host, '.');

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

    private function isPublicIp(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false
            && ! IpUtils::checkIp($address, self::BLOCKED_PUBLIC_RANGES);
    }

    /**
     * Hosts such as 2130706433, 0x7f000001 or 127.1 are IPv4 literals to
     * HTTP clients even though they are not dotted-quad notation.
     */
    private function looksNumeric(string $host): bool
    {
        $labels = explode('.', $host);

        return preg_match('/^(0x[0-9a-f]*|[0-9]+)$/i', (string) end($labels)) === 1;
    }

    private function notAllowed(): InvalidArgumentException
    {
        return new InvalidArgumentException('Provider endpoint is not allowed.');
    }
}
