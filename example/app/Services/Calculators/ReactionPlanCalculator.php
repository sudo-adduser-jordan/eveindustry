<?php

namespace App\Services\Calculators;

use App\Models\EveType;
use App\Services\IndustryProfitCalculator;
use App\Services\MarketService;
use App\Services\ReactionPlannerService;
use App\Services\ReactionsCalculatorService;

/**
 * A reaction plan: the resolved chain (composite and intermediate slots,
 * raw materials) priced in one region, with job fee, SCC fee, profit and
 * margin from IndustryProfitCalculator.
 *
 * Shared by POST /api/calculators/reaction-planner/calculate and
 * POST /api/v1/calculators/reaction-planner. calculate() returns the old
 * route's body without "success".
 */
class ReactionPlanCalculator
{
    public function __construct(
        protected ReactionPlannerService $planner,
        protected MarketService $marketService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Input validated with the calculate rules
     * @return array<string, mixed>
     */
    public function calculate(array $validated): array
    {
        // Compute effective rig ME bonus from dropdown settings
        $rigTier = $validated['rig_tier'] ?? 'none';
        $securitySpace = $validated['security_space'] ?? 'lowsec';
        $globalRigBonus = ReactionsCalculatorService::resolveRigMeBonus($rigTier, $securitySpace);

        $result = $this->planner->resolveChain($validated['entries'], $globalRigBonus);

        // ── Market price lookups for cost/profit estimation ──────────
        $rawTypeIds = array_column($result['raw_materials'], 'type_id');
        $outputTypeIds = array_filter(array_column($result['composite_outputs'], 'product_type_id'));
        $allTypeIds = array_values(array_unique(array_merge($rawTypeIds, $outputTypeIds)));

        // Use selected trade hub prices (defaults to Jita)
        $priceRegionId = (int) ($validated['price_region_id'] ?? MarketService::DEFAULT_REGION_ID);
        $prices = $this->marketService->getSellPricesWithSource($allTypeIds, $priceRegionId);

        // Batch-fetch volumes for all type IDs (packaged_volume preferred, fallback to volume)
        $volumes = EveType::whereIn('type_id', $allTypeIds)
            ->get(['type_id', 'volume', 'packaged_volume'])
            ->mapWithKeys(fn ($t) => [$t->type_id => $t->packaged_volume ?? $t->volume ?? 0])
            ->all();

        // Attach unit_price and total_cost to each raw material
        $totalInputCost = 0;
        foreach ($result['raw_materials'] as &$mat) {
            $unitPrice = $prices[$mat['type_id']]['price'] ?? 0;
            $mat['price_source'] = $prices[$mat['type_id']]['source'] ?? MarketService::PRICE_SOURCE_NONE;
            $mat['unit_price'] = $unitPrice;
            $mat['total_cost'] = $unitPrice * $mat['quantity'];
            $mat['volume'] = $volumes[$mat['type_id']] ?? 0;
            $totalInputCost += $mat['total_cost'];
        }
        unset($mat);

        // Attach unit_price and total_value to each composite output
        $totalOutputValue = 0;
        foreach ($result['composite_outputs'] as &$output) {
            $pid = $output['product_type_id'] ?? null;
            $unitPrice = $pid ? ($prices[$pid]['price'] ?? 0) : 0;
            $output['price_source'] = $pid ? ($prices[$pid]['source'] ?? MarketService::PRICE_SOURCE_NONE) : MarketService::PRICE_SOURCE_NONE;
            $output['unit_price'] = $unitPrice;
            $output['total_value'] = $unitPrice * $output['total_output'];
            $totalOutputValue += $output['total_value'];
        }
        unset($output);

        // ── Job fee calculation ──────────────────────────────────────────
        // EVE Industry: job_fee = EIV × system_cost_index
        //               facility_tax = job_fee × facility_tax_rate
        //               total_job_cost = job_fee + facility_tax
        // EIV (Estimated Item Value) = sum of (adjusted_price × quantity) for all input materials
        $systemCostIndex = (float) ($validated['system_cost_index'] ?? 0);
        $facilityTaxRate = (float) ($validated['facility_tax'] ?? 0);

        ['job_fee' => $jobFee, 'facility_tax' => $facilityTax, 'total_job_cost' => $totalJobCost] = IndustryProfitCalculator::jobCost($totalInputCost, $systemCostIndex, $facilityTaxRate);

        // SCC fee, profit and margin on the same basis as manufacturing
        $summary = IndustryProfitCalculator::summary($totalOutputValue, $totalInputCost, $totalJobCost);
        $sccFee = $summary['scc_fee'];
        $profit = $summary['profit'];
        $profitMargin = $summary['profit_margin'];

        return [
            'effective_rig_bonus' => round($globalRigBonus, 2),
            'total_input_cost' => $totalInputCost,
            'total_output_value' => $totalOutputValue,
            'system_cost_index' => $systemCostIndex,
            'facility_tax_rate' => $facilityTaxRate,
            'job_fee' => $jobFee,
            'facility_tax' => $facilityTax,
            'total_job_cost' => $totalJobCost,
            'scc_fee' => $sccFee,
            'profit' => $profit,
            'profit_margin' => $profitMargin,
            ...$result,
        ];
    }
}
