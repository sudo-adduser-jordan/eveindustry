<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;

/**
 * An invention + T2 manufacturing chain from InventionJobCalculator,
 * regrouped for v1.
 *
 * Map from the old flat keys: invention.* = probability, expected_attempts,
 * total_attempts, invention_cost_per_attempt / _per_success,
 * total_invention_cost, datacore_cost_per_attempt, datacores, decryptor_*;
 * material_costs.buy / build = total_t2_material_cost / total_t2_build_cost;
 * t2_job = t2_job_fee, t2_facility_tax, t2_total_job_cost; output =
 * t2_sell_price, total_output, total_output_value; best = total_cost,
 * profit, profit_margin, profit_per_unit (the cheaper scenario);
 * scenarios.full_buy / full_build = *_full_buy / *_full_build.
 */
class InventionJobResource extends CalculatorResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var array{probability: float, expected_attempts: float, total_attempts: int, cost_per_attempt: float, cost_per_success: float, total_cost: float, datacore_cost_per_attempt: float, datacores: list<array{type_id: int, name: string, quantity: int, unit_price: float, total_cost: float, volume: float, total_quantity: int, total_quantity_cost: float}>, decryptor: array{unit_price: float, volume: float, total_quantity: int, total_cost: float}} */
            'invention' => [
                'probability' => $r['probability'],
                'expected_attempts' => $r['expected_attempts'],
                'total_attempts' => $r['total_attempts'],
                'cost_per_attempt' => $r['invention_cost_per_attempt'],
                'cost_per_success' => $r['invention_cost_per_success'],
                'total_cost' => $r['total_invention_cost'],
                'datacore_cost_per_attempt' => $r['datacore_cost_per_attempt'],
                'datacores' => self::clean($r['datacores']),
                'decryptor' => [
                    'unit_price' => $r['decryptor_unit_price'],
                    'volume' => $r['decryptor_volume'],
                    'total_quantity' => $r['decryptor_total_quantity'],
                    'total_cost' => $r['decryptor_total_cost'],
                ],
            ],
            /** @var array{type_id: int, name: string|null} */
            't1_item' => [
                'type_id' => $r['t1_item_type_id'],
                'name' => $r['t1_item_name'],
            ],
            /** @var array{me: int, te: int, runs: int, decryptor: array{name: string, type_id: int|null, probability_multiplier: float, me_modifier: int, te_modifier: int, run_modifier: int}} */
            't2_bpc' => $r['t2_bpc'],
            /**
             * T2 materials with buy price, volume and, when buildable, the component build (ME 10, with job cost).
             *
             * @var list<array{type_id: int, name: string, base_quantity: int, adjusted_quantity: int, quantity_per_run: int, unit_price: float, total_cost: float, volume: float, component: array{is_buildable: bool, blueprint_type_id: int, portion_size: int, runs_needed: int, base_time: int, materials: list<array{type_id: int, name: string, base_quantity: int, adjusted_quantity: int, quantity_per_run: int, unit_price: float, total_cost: float, volume: float}>, build_cost: float, material_cost: float, job_cost: float}|null}>
             */
            't2_materials' => self::clean($r['t2_materials']),
            /** @var array{buy: float, build: float} */
            'material_costs' => [
                'buy' => $r['total_t2_material_cost'],
                'build' => $r['total_t2_build_cost'],
            ],
            /** @var array{job_fee: float, facility_tax: float, total: float} */
            't2_job' => [
                'job_fee' => $r['t2_job_fee'],
                'facility_tax' => $r['t2_facility_tax'],
                'total' => $r['t2_total_job_cost'],
            ],
            /** @var array{sell_price: float, total_output: int, total_value: float} */
            'output' => [
                'sell_price' => $r['t2_sell_price'],
                'total_output' => $r['total_output'],
                'total_value' => $r['total_output_value'],
            ],
            /** @var array{me: float, te: float} */
            'structure_bonuses' => [
                'me' => $r['structure_me_bonus'],
                'te' => $r['structure_te_bonus'],
            ],
            /** @var bool */
            'build_cheaper' => $r['build_cheaper'],
            /**
             * The cheaper of the two scenarios.
             *
             * @var array{total_cost: float, profit: float, profit_margin: float, profit_per_unit: float}
             */
            'best' => [
                'total_cost' => $r['total_cost'],
                'profit' => $r['profit'],
                'profit_margin' => $r['profit_margin'],
                'profit_per_unit' => $r['profit_per_unit'],
            ],
            /** @var array{full_buy: array{total_cost: float, profit: float, margin: float, per_unit: float}, full_build: array{total_cost: float, profit: float, margin: float, per_unit: float}} */
            'scenarios' => [
                'full_buy' => [
                    'total_cost' => $r['total_cost_full_buy'],
                    'profit' => $r['profit_full_buy'],
                    'margin' => $r['margin_full_buy'],
                    'per_unit' => $r['per_unit_full_buy'],
                ],
                'full_build' => [
                    'total_cost' => $r['total_cost_full_build'],
                    'profit' => $r['profit_full_build'],
                    'margin' => $r['margin_full_build'],
                    'per_unit' => $r['per_unit_full_build'],
                ],
            ],
        ];
    }
}
