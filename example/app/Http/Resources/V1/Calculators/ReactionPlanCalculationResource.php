<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;

/**
 * A priced reaction plan from ReactionPlanCalculator. Slot counts, totals
 * and fees are grouped; the chain lists are as the old route returned them.
 */
class ReactionPlanCalculationResource extends CalculatorResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var float */
            'effective_rig_bonus' => $r['effective_rig_bonus'],
            /** @var list<array{formula_type_id: int, product_type_id: int, product_name: string, slots: int, runs_per_slot: int, total_runs: int, total_output: int, portion_size: int, price_source: string|null, unit_price: float, total_value: float}> */
            'composite_outputs' => $r['composite_outputs'],
            /** @var list<array{formula_type_id: int, product_name: string, slots: int}> */
            'composite_slots' => $r['composite_slots'],
            /** @var list<array{formula_type_id: int, product_type_id: int, product_name: string, slots: int, runs_per_slot: int, total_runs: int}> */
            'intermediate_slots' => $r['intermediate_slots'],
            /** @var list<array{type_id: int, name: string, quantity: int, price_source: string|null, unit_price: float, total_cost: float, volume: float}> */
            'raw_materials' => $r['raw_materials'],
            /** @var array{composite: int, intermediate: int, total: int} */
            'slots' => [
                'composite' => $r['total_composite_slots'],
                'intermediate' => $r['total_intermediate_slots'],
                'total' => $r['total_slots'],
            ],
            /** @var array{input_cost: float, output_value: float} */
            'totals' => [
                'input_cost' => $r['total_input_cost'],
                'output_value' => $r['total_output_value'],
            ],
            /** @var array{system_cost_index: float, facility_tax_rate: float, job_fee: float, facility_tax: float, total_job_cost: float, scc_fee: float} */
            'fees' => [
                'system_cost_index' => $r['system_cost_index'],
                'facility_tax_rate' => $r['facility_tax_rate'],
                'job_fee' => $r['job_fee'],
                'facility_tax' => $r['facility_tax'],
                'total_job_cost' => $r['total_job_cost'],
                'scc_fee' => $r['scc_fee'],
            ],
            /** @var float */
            'profit' => $r['profit'],
            /** @var float */
            'profit_margin' => $r['profit_margin'],
        ];
    }
}
