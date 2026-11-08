<?php

return [
    // Enable only after the asset snapshot migration has been deployed.
    'durable_snapshots' => env('ASSET_DURABLE_SNAPSHOTS', true),
    'legacy_cache_dual_write' => env('ASSET_LEGACY_CACHE_DUAL_WRITE', false),
    'retained_generations' => 2,
    'snapshot_lock_seconds' => 210,
];
