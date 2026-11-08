<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;

/**
 * A manufacturing job from ManufacturingJobCalculator, regrouped for v1.
 *
 * Map from the old flat keys: scenarios.full_buy / full_build hold the
 * *_full_buy / *_full_build values (material_cost = total_cost_full_*,
 * job_fees = build_fee.total / build_fee.total_full_build, total_fees =
 * total_fee_full_*, total_cost = total_cost_full_*_with_fee,
 * profit_after_fees / margin_after_fees / per_unit_after_fees =
 * *_with_fee). market_buy is the old cost_profit block (unrounded,
 * buy-everything, no job fees). total_buy_cost / total_build_cost are the
 * scenarios' material_cost.
 */
class ManufacturingJobResource extends CalculatorResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $cp = $r['cost_profit'];
        $fee = $r['build_fee'];

        return [
            /** @var array{sell_price: float, price_source: string|null, total_output: int, total_revenue: float} Revenue is rounded to 0.01 ISK. */
            'product' => [
                'sell_price' => $cp['product_sell_price'],
                'price_source' => $cp['product_price_source'],
                'total_output' => $r['total_output'],
                'total_revenue' => $r['total_revenue'],
            ],
            /** @var array{base_time_seconds: int, time_per_run_seconds: int, total_time_seconds: int} */
            'time' => self::clean($r['time']),
            /**
             * Each material with buy price, volume and, when buildable, its component build (ME 10).
             *
             * @var list<array{type_id: int, name: string, base_quantity: int, adjusted_quantity: int, quantity_per_run: int, unit_price: float, total_cost: float, price_source: string|null, volume: float, component: array{is_buildable: bool, blueprint_type_id: int, portion_size: int, runs_needed: int, base_time: int, materials: list<array{type_id: int, name: string, base_quantity: int, adjusted_quantity: int, quantity_per_run: int, unit_price: float, total_cost: float, volume: float}>, build_cost: float}|null}>
             */
            'materials' => self::clean($r['materials']),
            'build_cheaper' => (bool) $r['build_cheaper'],
            /**
             * 4% of output value.
             *
             * @var float
             */
            'scc_fee' => $r['scc_fee'],
            /** @var array{eiv: float, sci: float, job_cost: float, tax_rate: float, facility_tax: float, total: float, components_fee: float, total_full_build: float, has_data: bool} total is the main job fee (job cost + facility tax); total_full_build adds every buildable component; has_data is true when a manufacturing or reaction system was given. */
            'build_fee' => [
                'eiv' => $fee['eiv'],
                'sci' => $fee['sci'],
                'job_cost' => $fee['job_cost'],
                'tax_rate' => $fee['tax_rate'],
                'facility_tax' => $fee['facility_tax'],
                'total' => $fee['total'],
                'components_fee' => $fee['components_fee'],
                'total_full_build' => $fee['total_full_build'],
                'has_data' => $fee['has_data'],
            ],
            /** @var array{full_buy: array{material_cost: float, profit: float, margin: float, per_unit: float, job_fees: float, total_fees: float, total_cost: float, profit_after_fees: float, margin_after_fees: float, per_unit_after_fees: float}, full_build: array{material_cost: float, profit: float, margin: float, per_unit: float, job_fees: float, total_fees: float, total_cost: float, profit_after_fees: float, margin_after_fees: float, per_unit_after_fees: float}} */
            'scenarios' => [
                'full_buy' => [
                    'material_cost' => $r['total_cost_full_buy'],
                    'profit' => $r['profit_full_buy'],
                    'margin' => $r['margin_full_buy'],
                    'per_unit' => $r['per_unit_full_buy'],
                    'job_fees' => $fee['total'],
                    'total_fees' => $r['total_fee_full_buy'],
                    'total_cost' => $r['total_cost_full_buy_with_fee'],
                    'profit_after_fees' => $r['profit_full_buy_with_fee'],
                    'margin_after_fees' => $r['margin_full_buy_with_fee'],
                    'per_unit_after_fees' => $r['per_unit_full_buy_with_fee'],
                ],
                'full_build' => [
                    'material_cost' => $r['total_cost_full_build'],
                    'profit' => $r['profit_full_build'],
                    'margin' => $r['margin_full_build'],
                    'per_unit' => $r['per_unit_full_build'],
                    'job_fees' => $fee['total_full_build'],
                    'total_fees' => $r['total_fee_full_build'],
                    'total_cost' => $r['total_cost_full_build_with_fee'],
                    'profit_after_fees' => $r['profit_full_build_with_fee'],
                    'margin_after_fees' => $r['margin_full_build_with_fee'],
                    'per_unit_after_fees' => $r['per_unit_full_build_with_fee'],
                ],
            ],
            /** @var array{material_cost: float, total_revenue: float, scc_fee: float, profit: float, profit_positive: bool, profit_per_unit: float, profit_margin: float} */
            'market_buy' => [
                'material_cost' => $cp['total_material_cost'],
                'total_revenue' => $cp['total_revenue'],
                'scc_fee' => $cp['scc_fee'],
                'profit' => $cp['profit'],
                'profit_positive' => $cp['profit_positive'],
                'profit_per_unit' => $cp['profit_per_unit'],
                'profit_margin' => $cp['profit_margin'],
            ],
        ];
    }
}
