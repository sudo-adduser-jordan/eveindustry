<?php

return [
    // Master switch. Capture is passive: it never issues ESI requests.
    'enabled' => env('ESI_DIAGNOSTICS_ENABLED', false),
    // Cache hit/miss/revalidation events are far more frequent than HTTP
    // attempts, so they have their own switch.
    'capture_cache' => env('ESI_DIAGNOSTICS_CAPTURE_CACHE', true),
    'capture_deferrals' => env('ESI_DIAGNOSTICS_CAPTURE_DEFERRALS', true),
    'release' => env('APP_RELEASE'),
    // Serialized event ceiling in bytes; larger events are dropped, not stored.
    'max_event_bytes' => 4096,
    // 'redis' buffers on a stream; anything else discards events (capture off the request path only).
    'sink' => env('ESI_DIAGNOSTICS_SINK', 'null'),
    'redis' => [
        // Must be the non-evicting Redis. The entry cap is not a byte limit: measure overhead before raising it.
        'connection' => env('ESI_DIAGNOSTICS_REDIS_CONNECTION', 'default'),
        'key' => 'esi:diag:stream',
        'max_entries' => (int) env('ESI_DIAGNOSTICS_MAX_ENTRIES', 100000),
    ],
    'flush' => [
        'batch' => 200,
        'max_seconds' => 45,
    ],
    'health' => [
        'stale_seconds' => 300,
    ],
    // Longest range an admin report may query.
    'max_range_days' => 31,
    'retention' => [
        'events_days' => (int) env('ESI_DIAGNOSTICS_EVENT_DAYS', 14),
        'endpoint_metrics_days' => (int) env('ESI_DIAGNOSTICS_ENDPOINT_DAYS', 90),
        'budget_minute_days' => 7,
        'budget_hour_days' => 90,
        'prune_batch' => 500,
    ],
];
