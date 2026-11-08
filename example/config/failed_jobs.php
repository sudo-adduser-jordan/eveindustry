<?php

return [
    // Retention stays disabled until operators have captured useful failure
    // diagnostics and explicitly opt in.
    'retention' => [
        'enabled' => env('FAILED_JOBS_RETENTION_ENABLED', false),
        'days' => (int) env('FAILED_JOBS_RETENTION_DAYS', 90),
        'batch_size' => 500,
    ],
];
