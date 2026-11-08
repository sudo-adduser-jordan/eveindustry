<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketPrice extends Model
{
    protected $table = 'market_prices';

    protected $fillable = [
        'type_id',
        'region_id',
        'average_price',
        'adjusted_price',
        'sell_min',
        'buy_max',
        'sell_volume',
        'buy_volume',
        'daily_volume',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'type_id' => 'integer',
            'region_id' => 'integer',
            'average_price' => 'float',
            'adjusted_price' => 'float',
            'sell_min' => 'float',
            'buy_max' => 'float',
            'sell_volume' => 'integer',
            'buy_volume' => 'integer',
            'daily_volume' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }
}
