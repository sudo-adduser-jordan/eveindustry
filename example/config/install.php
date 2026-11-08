<?php

/*
| First-run settings read by `php artisan app:install`, `admin:reset` and the
| database seeder. Read through config() (not env()) so they still work once
| `config:cache` has run.
*/

return [
    'super_admin' => [
        'name' => env('SUPER_ADMIN_NAME', 'Super Admin'),
        'email' => env('SUPER_ADMIN_EMAIL', 'admin@example.com'),
        // Empty means: generate a random password on first install and print it once.
        'password' => env('SUPER_ADMIN_PASSWORD'),
    ],
];
