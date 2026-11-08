<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'eve_auth'),
            'username' => env('DB_USERNAME', 'eve_auth'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
            'engine' => null,
            // Managed MySQL (DigitalOcean, RDS, ...) requires TLS: point this at the provider's CA certificate.
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
    ],
    'migrations' => 'migrations',
    /*
    | Two Redis roles. "default" holds queues, Horizon, batches' locks and
    | cache locks, and must never evict (maxmemory-policy noeviction).
    | "cache" holds the cache and sessions and may evict (allkeys-lru).
    | With only REDIS_HOST set both use one server (databases 0 and 1);
    | set REDIS_CACHE_HOST to put the cache on its own server.
    */
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'default' => [
            'scheme' => env('REDIS_SCHEME'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => (int) env('REDIS_DB', 0),
        ],
        'cache' => [
            'scheme' => env('REDIS_CACHE_SCHEME', env('REDIS_SCHEME')),
            'host' => env('REDIS_CACHE_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('REDIS_CACHE_USERNAME', env('REDIS_USERNAME')),
            'password' => env('REDIS_CACHE_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_CACHE_PORT', env('REDIS_PORT', '6379')),
            'database' => (int) env('REDIS_CACHE_DB', 1),
        ],
        // Sessions must not compete with bulk inventory payloads for LRU space.
        // Defaults to the persisted, non-evicting Redis, in a separate database.
        'sessions' => [
            'scheme' => env('REDIS_SESSION_SCHEME', env('REDIS_SCHEME')),
            'host' => env('REDIS_SESSION_HOST', env('REDIS_HOST', '127.0.0.1')),
            'username' => env('REDIS_SESSION_USERNAME', env('REDIS_USERNAME')),
            'password' => env('REDIS_SESSION_PASSWORD', env('REDIS_PASSWORD')),
            'port' => env('REDIS_SESSION_PORT', env('REDIS_PORT', '6379')),
            'database' => (int) env('REDIS_SESSION_DB', 2),
        ],
    ],
];
