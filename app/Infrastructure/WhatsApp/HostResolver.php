<?php

namespace App\Infrastructure\WhatsApp;

/** Resolves a hostname to its IP addresses; bound in tests to avoid real DNS. */
class HostResolver
{
    /**
     * @return list<string>
     */
    public function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];

        if (function_exists('dns_get_record')) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $addresses[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
