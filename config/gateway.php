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
        'https_hosts' => $hostList(env('GATEWAY_PROVIDER_HTTPS_HOSTS', 'api.fonnte.com,graph.facebook.com')),
        'http_hosts' => $hostList(env('GATEWAY_PROVIDER_HTTP_HOSTS')),
    ],

    'provider_health' => [
        'failure_threshold' => (int) env('GATEWAY_PROVIDER_FAILURE_THRESHOLD', 3),
        'circuit_open_seconds' => (int) env('GATEWAY_PROVIDER_CIRCUIT_SECONDS', 300),
    ],

    'seed' => [
        'administrator' => [
            'name' => env('GATEWAY_SEED_ADMIN_NAME') ?: 'Gateway Administrator',
            'email' => env('GATEWAY_SEED_ADMIN_EMAIL') ?: 'admin@gateway.local',
            'password' => env('GATEWAY_SEED_ADMIN_PASSWORD') ?: 'admin12345',
        ],
        'providers' => [
            'waha' => [
                'base_url' => env('GATEWAY_SEED_WAHA_BASE_URL'),
                'session' => env('GATEWAY_SEED_WAHA_SESSION'),
                'api_key' => env('GATEWAY_SEED_WAHA_API_KEY'),
            ],
            'fonnte' => [
                'endpoint' => env('GATEWAY_SEED_FONNTE_ENDPOINT', 'https://api.fonnte.com/send'),
                'token' => env('GATEWAY_SEED_FONNTE_TOKEN'),
            ],
            'gowa' => [
                'base_url' => env('GATEWAY_SEED_GOWA_BASE_URL'),
                'username' => env('GATEWAY_SEED_GOWA_USERNAME'),
                'password' => env('GATEWAY_SEED_GOWA_PASSWORD'),
                'device_id' => env('GATEWAY_SEED_GOWA_DEVICE_ID'),
            ],
            'waba' => [
                'base_url' => env('GATEWAY_SEED_WABA_BASE_URL', 'https://graph.facebook.com'),
                'api_version' => env('GATEWAY_SEED_WABA_API_VERSION', 'v25.0'),
                'phone_number_id' => env('GATEWAY_SEED_WABA_PHONE_NUMBER_ID'),
                'access_token' => env('GATEWAY_SEED_WABA_ACCESS_TOKEN'),
            ],
        ],
        'credentials' => [
            'appscript-ft' => env('GATEWAY_SEED_APPSCRIPT_FT_TOKEN'),
            'web-helpdesk' => env('GATEWAY_SEED_WEB_HELPDESK_TOKEN'),
            'web-sam' => env('GATEWAY_SEED_WEB_SAM_TOKEN'),
            'web-shelf' => env('GATEWAY_SEED_WEB_SHELF_TOKEN'),
        ],
    ],
];
