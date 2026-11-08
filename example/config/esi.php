<?php

return [
    'encrypt_tokens' => env('ESI_ENCRYPT_TOKENS', false),
    // Coordination data and locks must use the non-evicting Redis instance.
    'coordination_store' => env('ESI_COORDINATION_STORE', env('CACHE_STORE') === 'array' ? 'array' : 'esi_coordination'),
    'application_id' => env('EVE_CLIENT_ID', 'eve-industrialist'),
    'source_identity' => env('ESI_SOURCE_IDENTITY', 'shared-egress'),
    'user_agent' => env('ESI_USER_AGENT', 'EveIndustrialist/2.0 (+https://github.com/Eve-Industry/eve-industry)'),
    'compatibility_date' => '2025-12-16',
    'connect_timeout' => 5,
    'timeout' => 30,
    'pagination_safety_limit' => 10000,
    'http_cache_store' => env('ESI_HTTP_CACHE_STORE'),
    'http_cache_retention' => 86400,
    'http_cache_max_bytes' => 4 * 1024 * 1024,
];
