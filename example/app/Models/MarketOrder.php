<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketOrder extends Model
{
    protected $table = 'market_orders';

    protected $primaryKey = 'order_id';

    public $incrementing = false;

    protected $fillable = [
        'order_id',
        'region_id',
        'type_id',
        'is_buy_order',
        'price',
        'volume_remain',
        'volume_total',
        'min_volume',
        'location_id',
        'duration',
        'range',
        'issued',
        'batch_id',
    ];

    protected function casts(): array
    {
        return [
            'order_id'      => 'integer',
            'region_id'     => 'integer',
            'type_id'       => 'integer',
            'is_buy_order'  => 'boolean',
            'price'         => 'float',
            'volume_remain' => 'integer',
            'volume_total'  => 'integer',
            'min_volume'    => 'integer',
            'location_id'   => 'integer',
            'duration'      => 'integer',
            'issued'        => 'datetime',
        ];
    }
}
