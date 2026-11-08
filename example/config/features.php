<?php

// Code-only switches for unfinished features. There is deliberately no
// environment variable: a self-hosted install cannot turn these on by accident.
return [

    // Discord welcome bot (work in progress). Off: the admin page, its
    // /api/v1/admin/discord-bot endpoints and `php artisan discord:bot` are hidden.
    'discord_bot' => false,

];
