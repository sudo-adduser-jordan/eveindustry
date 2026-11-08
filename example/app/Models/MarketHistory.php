<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketHistory extends Model
{
    protected $table = 'market_history';

    protected $fillable = [
        'region_id',
        'type_id',
        'date',
        'average',
        'highest',
        'lowest',
        'volume',
        'order_count',
    ];

    protected function casts(): array
    {
        return [
            'region_id'   => 'integer',
            'type_id'     => 'integer',
            'date'        => 'date',
            'average'     => 'float',
            'highest'     => 'float',
            'lowest'      => 'float',
            'volume'      => 'integer',
            'order_count' => 'integer',
        ];
    }
}
