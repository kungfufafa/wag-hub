<?php

$hostList = static function (?string $value): array {
    if ($value === null || trim($value) === '') {
        return [];
    }

    return array_values(array_unique(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', $value),
    ))));
};

return [
    'provider_endpoints' => [
        'https_hosts' => $hostList(env('GATEWAY_PROVIDER_HTTPS_HOSTS', 'api.fonnte.com')),
        'http_hosts' => $hostList(env('GATEWAY_PROVIDER_HTTP_HOSTS')),
    ],

    'provider_health' => [
        'failure_threshold' => (int) env('GATEWAY_PROVIDER_FAILURE_THRESHOLD', 3),
        'circuit_open_seconds' => (int) env('GATEWAY_PROVIDER_CIRCUIT_SECONDS', 300),
    ],
];
