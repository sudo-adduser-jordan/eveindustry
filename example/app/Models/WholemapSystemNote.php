<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WholemapSystemNote extends Model
{
    protected $table = 'wholemap_system_notes';

    protected $fillable = [
        'user_id',
        'system_id',
        'system_name',
        'status',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'system_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
