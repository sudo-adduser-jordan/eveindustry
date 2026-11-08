<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One region order sweep: fetch every ESI page, then (only if every page
 * arrived) delete orders the sweep did not see and rebuild market_aggregates.
 */
class MarketOrderSweep extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETING = 'completing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** A running sweep that never finished (worker died, batch lost). */
    public const STATUS_ABANDONED = 'abandoned';

    protected $table = 'market_order_sweeps';

    protected $fillable = [
        'sweep_id',
        'region_id',
        'generation',
        'status',
        'started_at',
        'finished_at',
        'duration_seconds',
        'pages_total',
        'pages_fetched',
        'pages_not_modified',
        'orders_seen',
        'orders_deleted',
        'types_aggregated',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'generation' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'integer',
            'pages_total' => 'integer',
            'pages_fetched' => 'integer',
            'pages_not_modified' => 'integer',
            'orders_seen' => 'integer',
            'orders_deleted' => 'integer',
            'types_aggregated' => 'integer',
        ];
    }
}
