<?php

return [
    // Cleanup is bounded and preserves the active generation. Set false to
    // pause scheduled deletes while leaving the command's dry run available.
    'enabled' => env('SNAPSHOT_RETENTION_ENABLED', true),
    'batch_size' => 500,
    'market' => [
        'generations' => 2,
        // Price entries live for two hours; leave an additional hour for readers.
        'grace_hours' => 3,
    ],
    'public_contracts' => [
        'generations' => 2,
        'grace_hours' => 3,
        // Failed and superseded imports are retained for diagnosis for one day.
        'failed_after_hours' => 24,
        'writer_lock_seconds' => 3600,
    ],
];
