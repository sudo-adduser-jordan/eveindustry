<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;

/**
 * One blueprint in the manufacturing browser. Covers both the live rows
 * (ManufacturingBrowserService::calculateAllProfitability) and the
 * precomputed rows (browsePrecomputed); the precomputed-only fields
 * (input_cost, output_value, base_profit, base_profit_margin,
 * base_profit_positive: the stored 1-run, no-fee figures) are null on live
 * rows.
 */
class ManufacturingBrowserItemResource extends CalculatorResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var int */
            'blueprint_type_id' => $r['blueprint_type_id'],
            /** @var string */
            'blueprint_name' => $r['blueprint_name'],
            /** @var int */
            'product_type_id' => $r['product_type_id'],
            /** @var string */
            'product_name' => $r['product_name'],
            /** @var string */
            'product_icon_url' => $r['product_icon_url'],
            /** @var int */
            'portion_size' => $r['portion_size'],
            /** @var int */
            'total_output' => $r['total_output'],
            /** @var float */
            'total_input_cost' => $r['total_input_cost'],
            /** @var float */
            'total_output_value' => $r['total_output_value'],
            /** @var float */
            'job_fee' => $r['job_fee'],
            /** @var float */
            'facility_tax' => $r['facility_tax'],
            /** @var float */
            'total_job_cost' => $r['total_job_cost'],
            /** @var float */
            'scc_fee' => $r['scc_fee'],
            /** @var float */
            'profit' => $r['profit'],
            /** @var bool */
            'profit_positive' => $r['profit_positive'],
            /** @var float */
            'profit_per_unit' => $r['profit_per_unit'],
            /** @var float */
            'profit_margin' => $r['profit_margin'],
            /** @var array{input_cost: float, output_value: float, profit: float, profit_margin: float, profit_positive: bool}|null */
            'base' => array_key_exists('base_profit', $r) ? [
                'input_cost' => $r['input_cost'],
                'output_value' => $r['output_value'],
                'profit' => $r['base_profit'],
                'profit_margin' => $r['base_profit_margin'],
                'profit_positive' => $r['base_profit_positive'],
            ] : null,
            /** @var array{score: int, avg_daily_volume: float, avg_order_count: float, active_days: int, total_days: int} */
            'sell_score' => [
                'score' => $r['sell_score'],
                'avg_daily_volume' => $r['sell_score_avg_daily_volume'],
                'avg_order_count' => $r['sell_score_avg_order_count'],
                'active_days' => $r['sell_score_active_days'],
                'total_days' => $r['sell_score_total_days'],
            ],
        ];
    }
}
