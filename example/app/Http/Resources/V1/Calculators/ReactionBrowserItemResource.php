<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;

/**
 * One reaction in the reaction browser (ReactionBrowserService row).
 */
class ReactionBrowserItemResource extends CalculatorResource
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
            'formula_name' => $r['formula_name'],
            /** @var string composite, biochemical or hybrid. */
            'reaction_category' => $r['reaction_category'],
            /** @var string intermediate or complex. */
            'reaction_complexity' => $r['reaction_complexity'],
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
