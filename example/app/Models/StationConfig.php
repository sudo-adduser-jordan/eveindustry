<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationConfig extends Model
{
    protected $fillable = [
        'user_id',
        'activity_type',
        'name',
        'structure_type',
        'rig_me',
        'rig_te',
        'system_security',
        'solar_system_id',
        'tax_rate',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'solar_system_id' => 'integer',
            'tax_rate' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
