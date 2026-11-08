<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManufacturingCost extends Model
{
    protected $table = 'manufacturing_costs';

    protected $fillable = [
        'product_type_id',
        'blueprint_type_id',
        'region_id',
        'me_level',
        'product_name',
        'blueprint_name',
        'portion_size',
        'input_cost',
        'output_value',
        'base_profit',
        'profit_margin',
        'sell_score',
        'sell_score_avg_daily_volume',
        'sell_score_avg_order_count',
        'sell_score_active_days',
        'sell_score_total_days',
        'prices_as_of',
    ];

    protected function casts(): array
    {
        return [
            'product_type_id' => 'integer',
            'blueprint_type_id' => 'integer',
            'region_id' => 'integer',
            'me_level' => 'integer',
            'portion_size' => 'integer',
            'input_cost' => 'float',
            'output_value' => 'float',
            'base_profit' => 'float',
            'profit_margin' => 'float',
            'sell_score' => 'integer',
            'sell_score_avg_daily_volume' => 'float',
            'sell_score_avg_order_count' => 'float',
            'sell_score_active_days' => 'integer',
            'sell_score_total_days' => 'integer',
            'prices_as_of' => 'datetime',
        ];
    }
}
