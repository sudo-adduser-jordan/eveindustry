<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReactionPlan extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'entries',
        'default_runs_per_slot',
        'rig_bonus',
        'structure_type',
        'rig_tier',
        'security_space',
        'facility_tax',
        'station_id',
        'solar_system_id',
        'price_region_id',
    ];

    protected function casts(): array
    {
        return [
            'entries' => 'array',
            'default_runs_per_slot' => 'integer',
            'rig_bonus' => 'decimal:2',
            'facility_tax' => 'decimal:2',
            'station_id' => 'integer',
            'solar_system_id' => 'integer',
            'price_region_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
