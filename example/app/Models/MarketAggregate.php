<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Order-book summary for one type, region and completed sweep generation.
 * Written by MarketAggregateService.
 */
class MarketAggregate extends Model
{
    protected $table = 'market_aggregates';

    protected $fillable = [
        'region_id',
        'type_id',
        'sweep_id',
        'snapshot_at',
        'best_bid',
        'best_ask',
        'sell_price_p5',
        'sell_volume',
        'buy_volume',
        'sell_orders',
        'buy_orders',
    ];

    protected function casts(): array
    {
        return [
            'region_id' => 'integer',
            'type_id' => 'integer',
            'snapshot_at' => 'datetime',
            'best_bid' => 'float',
            'best_ask' => 'float',
            'sell_price_p5' => 'float',
            'sell_volume' => 'integer',
            'buy_volume' => 'integer',
            'sell_orders' => 'integer',
            'buy_orders' => 'integer',
        ];
    }
}
