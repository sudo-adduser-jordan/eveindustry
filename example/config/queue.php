<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | retry_after must be longer than the longest $timeout of any job a
    | connection's workers run, or a job still running is handed to a second
    | worker. Laravel's default is 90 s, which is shorter than most jobs here.
    |
    | Jobs are dispatched on the default "redis" connection. All Redis queue
    | connections share one Redis database, so a queue name maps to the same
    | key on each; the worker's connection decides retry_after. Horizon runs
    | "updates" on "redis-long" and "market" on "redis-market" (see
    | config/horizon.php).
    |
    | tests/Unit/JobTimeoutTest.php enforces timeout < retry_after - 30.
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 960),
            'after_commit' => false,
        ],

        // Short, user-facing jobs on the "default" queue (timeouts up to 180 s).
        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 240),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Long imports and precompute on the "updates" queue (timeouts up to 900 s).
        'redis-long' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'updates',
            'retry_after' => (int) env('REDIS_LONG_QUEUE_RETRY_AFTER', 960),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Region order sweeps on the "market" queue: page fetches (60 s) and
        // the sweep completion / aggregate rebuild (timeouts up to 300 s).
        'redis-market' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'market',
            'retry_after' => (int) env('REDIS_MARKET_QUEUE_RETRY_AFTER', 420),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
