<?php

return [
    'queue_connection' => env('WAG_ENGINE_QUEUE_CONNECTION', 'database'),
    'default_limit' => (int) env('WAG_DEFAULT_SESSION_LIMIT', 5),
    'default_ceiling' => (int) env('WAG_DEFAULT_SESSION_CEILING', 10),
    'stale_after_seconds' => 30,
    'provisioning_minutes' => 10,
    'media_disk' => 'local',
    'media_max_bytes' => 16 * 1024 * 1024,
    'ffmpeg_binary' => env('WAG_FFMPEG_BINARY', 'ffmpeg'),
    'ffprobe_binary' => env('WAG_FFPROBE_BINARY', 'ffprobe'),
    'voice_timeout_seconds' => 30,
    'voice_max_seconds' => 300,
    'webhook_allow_private_hosts' => array_values(array_filter(explode(',', (string) env('WAG_WEBHOOK_PRIVATE_HOSTS', '')))),
    'event_retention_days' => 7,
    'webhook_retry_hours' => 24,
    'scopes' => ['integration:read', 'sessions:read', 'sessions:manage', 'messages:read', 'messages:send', 'media:read', 'media:write', 'webhooks:manage', 'capacity:manage'],
];
