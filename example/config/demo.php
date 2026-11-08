<?php

return [
    // Opt-in: the "Try the demo" login and its daily reset only exist when enabled.
    'enabled' => (bool) env('DEMO_ENABLED', false),

    'email' => 'demo@eve.local',

    'character_count' => 3,

    // Local test runs and CI can shrink the data volume.
    'reset_time' => env('DEMO_RESET_TIME', '03:30'),
];
