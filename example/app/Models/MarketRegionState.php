<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Durable active-generation pointer and fencing counter for a market region. */
class MarketRegionState extends Model
{
    protected $table = 'market_region_states';

    protected $primaryKey = 'region_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'region_id',
        'latest_generation',
        'latest_sweep_id',
        'latest_started_at',
        'active_generation',
        'active_sweep_id',
        'active_snapshot_at',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'latest_generation' => 'integer',
            'latest_started_at' => 'datetime',
            'active_generation' => 'integer',
            'active_snapshot_at' => 'datetime',
        ];
    }
}
