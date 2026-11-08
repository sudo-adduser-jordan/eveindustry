<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationPreference extends Model
{
    protected $fillable = ['user_id', 'type', 'enabled', 'lead_minutes', 'character_ids'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'lead_minutes' => 'integer',
            'character_ids' => 'array',
        ];
    }
}
