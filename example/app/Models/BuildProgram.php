<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuildProgram extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'program_type',
        't2_type_id',
        't2_type_name',
        'steps',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            't2_type_id' => 'integer',
            'steps' => 'array',
            'settings' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
