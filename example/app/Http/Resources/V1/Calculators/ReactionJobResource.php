<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;

/**
 * One reaction job from ReactionJobCalculator. The old cost_profit block
 * is split into product and summary; materials appear once.
 */
class ReactionJobResource extends CalculatorResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $cp = $this->resource['cost_profit'];

        return [
            /** @var array{sell_price: float, price_source: string|null, total_output: int, total_revenue: float} */
            'product' => [
                'sell_price' => $cp['product_sell_price'],
                'price_source' => $cp['product_price_source'],
                'total_output' => $cp['total_output'],
                'total_revenue' => $cp['total_revenue'],
            ],
            /** @var array{base_time_seconds: int, time_per_run_seconds: int, total_time_seconds: int} */
            'time' => self::clean($this->resource['time']),
            /** @var list<array{type_id: int, name: string, base_quantity: int, adjusted_quantity: int, quantity_per_run: int, unit_price: float, total_cost: float, price_source: string|null}> */
            'materials' => self::clean($this->resource['materials']),
            /** @var array{material_cost: float, scc_fee: float, profit: float, profit_positive: bool, profit_per_unit: float, profit_margin: float} */
            'summary' => [
                'material_cost' => $cp['total_material_cost'],
                'scc_fee' => $cp['scc_fee'],
                'profit' => $cp['profit'],
                'profit_positive' => $cp['profit_positive'],
                'profit_per_unit' => $cp['profit_per_unit'],
                'profit_margin' => $cp['profit_margin'],
            ],
        ];
    }
}
