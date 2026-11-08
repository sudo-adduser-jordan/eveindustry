<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DiscordBotSetting extends Model
{
    protected $fillable = [
        'bot_token',
        'bot_name',
        'bot_avatar_url',
        'welcome_channel_id',
        'welcome_message',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * Retrieve the singleton settings row, creating it with defaults if needed.
     */
    public static function getSettings(): self
    {
        return self::firstOrCreate([], [
            'bot_name' => 'EVE Industrialist Bot',
            'welcome_message' => 'Welcome to the server, {user}!',
            'is_enabled' => false,
        ]);
    }
}
