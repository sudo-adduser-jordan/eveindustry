<?php

return [
    /*
    | VAPID identifies this server to browser push services. Generate a pair with
    | `php artisan notifications:vapid-keys` and set both keys in the environment.
    | Push stays off, and the settings page says so, until both keys are present.
    */
    'vapid' => [
        'subject' => env('WEBPUSH_VAPID_SUBJECT', env('APP_URL', 'mailto:admin@example.com')),
        'public_key' => env('WEBPUSH_VAPID_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_VAPID_PRIVATE_KEY'),
    ],

    // Seconds a push service keeps an undelivered message while the device is offline.
    'ttl' => (int) env('WEBPUSH_TTL', 3600),
];
