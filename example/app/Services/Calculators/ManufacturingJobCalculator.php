<?php

namespace App\Services\Calculators;

use App\Models\EveType;
use App\Services\EsiService;
use App\Services\IndustryProfitCalculator;
use App\Services\InventionPlannerService;
use App\Services\ManufacturingCalculatorService;
use App\Services\MarketService;

/**
 * One manufacturing job: materials, time, buy-vs-build, job fees (EIV × SCI
 * + facility tax) and the full-buy / full-build profit scenarios.
 *
 * Shared by POST /api/calculators/manufacturing/calculate and
 * POST /api/v1/calculators/manufacturing. calculate() returns the old
 * route's body without "success".
 */
class ManufacturingJobCalculator
{
    public function __construct(
        protected ManufacturingCalculatorService $calculator,
        protected MarketService $marketService,
        protected EsiService $esi,
        protected InventionPlannerService $planner,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Input validated with the calculate rules
     *                                           (blueprint_type_id, product_type_id, runs, me_level, ...).
     *                                           Values are used as given: tax_rate is echoed back
     *                                           unchanged and portion_size is compared with === 1.
     * @return array<string, mixed>
     *
     * @throws CalculationException when the blueprint has no materials
     */
    public function calculate(array $validated): array
    {
        $runs = $validated['runs'];
        $meLevel = $validated['me_level'];
        $teLevel = $validated['te_level'];
        $structureMeBonus = $validated['structure_me_bonus'] ?? 0;
        $structureTeBonus = $validated['structure_te_bonus'] ?? 0;
        $reactionMeBonus = $validated['reaction_me_bonus'] ?? null;
        $portionSize = $validated['portion_size'] ?? 1;
        $regionId = $validated['region_id'] ?? MarketService::DEFAULT_REGION_ID;
        $buybackPercentage = isset($validated['buyback_percentage']) ? (float) $validated['buyback_percentage'] : null;
        $solarSystemId = $validated['solar_system_id'] ?? null;
        $taxRate = $validated['tax_rate'] ?? 0.0;
        $reactionSolarSystemId = $validated['reaction_solar_system_id'] ?? null;
        $reactionTaxRate = $validated['reaction_tax_rate'] ?? 0.0;

        // Auto-resolve materials from SDE if not provided by frontend
        $baseMaterials = $validated['materials'] ?? [];
        $baseTime = $validated['base_time'] ?? 0;

        if (empty($baseMaterials) || ! $baseTime) {
            $sdeData = $this->esi->resolveBlueprintActivity($validated['blueprint_type_id'], 1);
            if ($sdeData) {
                if (empty($baseMaterials)) {
                    $baseMaterials = $sdeData['materials'];
                }
                if (! $baseTime) {
                    $baseTime = $sdeData['time'];
                }
                if ($portionSize === 1 && ! empty($sdeData['products'])) {
                    $portionSize = $sdeData['products'][0]['quantity'] ?? 1;
                }
            }
        }

        if (empty($baseMaterials)) {
            throw new CalculationException('No materials found for this blueprint.', 'blueprint_type_id');
        }

        // Calculate materials
        $materials = $this->calculator->calculateMaterials(
            $baseMaterials,
            $meLevel,
            $runs,
            $structureMeBonus
        );

        // Calculate time
        $time = $this->calculator->calculateProductionTime(
            $baseTime,
            $teLevel,
            $runs,
            $validated['industry_skill'],
            $validated['advanced_industry_skill'],
            $structureTeBonus
        );

        // Resolve component build data for each material (assume ME10 for sub-components)
        $componentBuildData = $this->planner->resolveComponentBuildData($materials, 10, $structureMeBonus, $reactionMeBonus);

        // Collect all type IDs for price lookup (including sub-materials)
        $typeIds = array_column($materials, 'type_id');
        $typeIds[] = $validated['product_type_id'];
        foreach ($componentBuildData as $compData) {
            if ($compData && ! empty($compData['materials'])) {
                foreach ($compData['materials'] as $subMat) {
                    $typeIds[] = $subMat['type_id'];
                }
            }
        }
        $typeIds = array_values(array_unique(array_filter($typeIds)));

        // Ensure material prices are in database
        $this->marketService->fetchAndStoreRegionalPrices($typeIds, $regionId);

        // Calculate cost/profit (buy-all scenario)
        $costProfit = $this->calculator->calculateCostProfit(
            $materials,
            $validated['product_type_id'],
            $runs,
            $portionSize,
            $regionId,
            $buybackPercentage
        );

        // Get sell prices for build cost calculations
        $sellPrices = $this->marketService->getSellPrices($typeIds, $regionId);

        // Batch-fetch volumes for all type IDs (packaged_volume preferred, fallback to volume)
        $volumes = EveType::whereIn('type_id', $typeIds)
            ->get(['type_id', 'volume', 'packaged_volume'])
            ->mapWithKeys(fn ($t) => [$t->type_id => $t->packaged_volume ?? $t->volume ?? 0])
            ->all();

        // Buyback multiplier for sub-material (component build) pricing
        $buybackMultiplier = ($buybackPercentage !== null) ? ($buybackPercentage / 100.0) : 1.0;

        // Attach component build data and calculate build costs
        $totalBuyCost = 0;
        $totalBuildCost = 0;
        foreach ($costProfit['materials'] as &$mat) {
            $typeId = $mat['type_id'];
            $totalBuyCost += $mat['total_cost'];
            $mat['volume'] = $volumes[$typeId] ?? 0;

            $compData = $componentBuildData[$typeId] ?? null;
            if ($compData) {
                $buildCost = 0;
                foreach ($compData['materials'] as &$subMat) {
                    $subPrice = ($sellPrices[$subMat['type_id']] ?? 0) * $buybackMultiplier;
                    $subMat['unit_price'] = $subPrice;
                    $subMat['total_cost'] = $subPrice * $subMat['adjusted_quantity'];
                    $subMat['volume'] = $volumes[$subMat['type_id']] ?? 0;
                    $buildCost += $subMat['total_cost'];
                }
                unset($subMat);

                $mat['component'] = [
                    'is_buildable' => true,
                    'blueprint_type_id' => $compData['blueprint_type_id'],
                    'portion_size' => $compData['portion_size'],
                    'runs_needed' => $compData['runs_needed'],
                    'base_time' => $compData['base_time'],
                    'materials' => $compData['materials'],
                    'build_cost' => round($buildCost, 2),
                ];

                $totalBuildCost += $buildCost;
            } else {
                $mat['component'] = null;
                $totalBuildCost += $mat['total_cost'];
            }
        }
        unset($mat);

        $totalOutput = $costProfit['total_output'];
        $totalRevenue = $costProfit['total_revenue'];

        // Full Buy scenario (SCC fee always applied)
        $fullBuy = IndustryProfitCalculator::summary($totalRevenue, $totalBuyCost);
        $sccFee = $fullBuy['scc_fee'];
        $profitFullBuy = $fullBuy['profit'];
        $marginFullBuy = $fullBuy['profit_margin'];
        $perUnitFullBuy = $totalOutput > 0 ? $profitFullBuy / $totalOutput : 0;

        // Full Build scenario (SCC fee always applied)
        $fullBuild = IndustryProfitCalculator::summary($totalRevenue, round($totalBuildCost, 2));
        $profitFullBuild = $fullBuild['profit'];
        $marginFullBuild = $fullBuild['profit_margin'];
        $perUnitFullBuild = $totalOutput > 0 ? $profitFullBuild / $totalOutput : 0;

        $buildCheaper = $totalBuildCost < $totalBuyCost;

        // Build fee: job cost (EIV × SCI) + facility tax
        $eiv = 0.0;
        $sciManufacturing = 0.0;
        $sciReaction = 0.0;
        $jobCost = 0.0;
        $facilityTax = 0.0;
        $mainBuildFee = 0.0;
        $componentsFee = 0.0;
        $adjustedPrices = [];

        $needsFeeCalc = $solarSystemId || $reactionSolarSystemId;

        if ($needsFeeCalc) {
            // Fetch ESI adjusted prices for EIV calculations
            $marketPrices = $this->esi->getMarketPrices() ?? [];
            foreach ($marketPrices as $mp) {
                if (isset($mp['type_id'], $mp['adjusted_price'])) {
                    $adjustedPrices[(int) $mp['type_id']] = (float) $mp['adjusted_price'];
                }
            }

            // Fetch industry system cost indices once
            $indices = $this->esi->getIndustrySystemCostIndices() ?? [];
            foreach ($indices as $system) {
                $systemId = $system['solar_system_id'] ?? 0;
                if ($solarSystemId && $systemId === $solarSystemId) {
                    foreach ($system['cost_indices'] ?? [] as $ci) {
                        if ($ci['activity'] === 'manufacturing') {
                            $sciManufacturing = (float) $ci['cost_index'];
                            break;
                        }
                    }
                }
                if ($reactionSolarSystemId && $systemId === $reactionSolarSystemId) {
                    foreach ($system['cost_indices'] ?? [] as $ci) {
                        if ($ci['activity'] === 'reaction') {
                            $sciReaction = (float) $ci['cost_index'];
                            break;
                        }
                    }
                }
            }
        }

        if ($solarSystemId) {
            // Main job: EIV = sum(adjusted_price × adjusted_quantity) for top-level materials.
            // Only applies when a manufacturing structure system is configured. Component fees
            // can still exist independently when only a reaction system is configured.
            foreach ($costProfit['materials'] as $mat) {
                $eiv += ($adjustedPrices[$mat['type_id']] ?? 0.0) * $mat['adjusted_quantity'];
            }

            $jobCost = $eiv * $sciManufacturing;
            // Facility (station) tax is based on the estimated worth of the final output
            $facilityTax = $totalRevenue * ($taxRate / 100.0);
            $mainBuildFee = $jobCost + $facilityTax;
        }

        // Component fees: each buildable component also incurs a job cost
        if ($needsFeeCalc) {
            foreach ($costProfit['materials'] as $mat) {
                $compData = $componentBuildData[$mat['type_id']] ?? null;
                if (! $compData) {
                    continue;
                }

                $isReaction = $compData['is_reaction'] ?? false;
                $compSci = $isReaction ? $sciReaction : $sciManufacturing;
                $compTaxRate = $isReaction ? $reactionTaxRate : $taxRate;
                $compHasSystem = $isReaction ? (bool) $reactionSolarSystemId : (bool) $solarSystemId;

                if (! $compHasSystem || $compSci <= 0.0) {
                    continue;
                }

                $compEiv = 0.0;
                foreach ($compData['materials'] as $subMat) {
                    $compEiv += ($adjustedPrices[$subMat['type_id']] ?? 0.0) * $subMat['adjusted_quantity'];
                }

                $compJobCost = $compEiv * $compSci;
                // Facility (station) tax is based on the estimated worth of the component output
                $compFacilityTax = $mat['total_cost'] * ($compTaxRate / 100.0);
                $componentsFee += $compJobCost + $compFacilityTax;
            }
        }

        $totalBuildFee = $mainBuildFee; // fee for Full Buy (main item only)
        $totalBuildFeeFullBuild = $mainBuildFee + $componentsFee; // fee for Full Build (main + all components)

        // Full Buy with the main job fee. Margin is profit ÷ input cost, the
        // same rule as everywhere else; total cost is everything spent
        // (materials, job fee, SCC fee).
        $fullBuyWithFee = IndustryProfitCalculator::summary($totalRevenue, $totalBuyCost, $totalBuildFee);
        $profitFullBuyWithFee = $fullBuyWithFee['profit'];
        $totalFeeFullBuy = $totalBuildFee + $sccFee;
        $totalCostFullBuyWithFee = $totalBuyCost + $totalFeeFullBuy;
        $marginFullBuyWithFee = $fullBuyWithFee['profit_margin'];
        $perUnitFullBuyWithFee = $totalOutput > 0 ? $profitFullBuyWithFee / $totalOutput : 0;

        // Full Build with all job fees (main item and components)
        $fullBuildWithFee = IndustryProfitCalculator::summary($totalRevenue, round($totalBuildCost, 2), $totalBuildFeeFullBuild);
        $profitFullBuildWithFee = $fullBuildWithFee['profit'];
        $totalFeeFullBuild = $totalBuildFeeFullBuild + $sccFee;
        $totalCostFullBuildWithFee = round($totalBuildCost + $totalFeeFullBuild, 2);
        $marginFullBuildWithFee = $fullBuildWithFee['profit_margin'];
        $perUnitFullBuildWithFee = $totalOutput > 0 ? $profitFullBuildWithFee / $totalOutput : 0;

        return [
            'materials' => $costProfit['materials'],
            'time' => $time,
            'cost_profit' => $costProfit,
            'total_buy_cost' => round($totalBuyCost, 2),
            'total_build_cost' => round($totalBuildCost, 2),
            'build_cheaper' => $buildCheaper,
            'total_cost_full_buy' => round($totalBuyCost, 2),
            'profit_full_buy' => round($profitFullBuy, 2),
            'margin_full_buy' => $marginFullBuy,
            'per_unit_full_buy' => round($perUnitFullBuy, 2),
            'total_cost_full_build' => round($totalBuildCost, 2),
            'profit_full_build' => round($profitFullBuild, 2),
            'margin_full_build' => $marginFullBuild,
            'per_unit_full_build' => round($perUnitFullBuild, 2),
            'total_output' => $totalOutput,
            'total_revenue' => round($totalRevenue, 2),
            'scc_fee' => round($sccFee, 2),
            'build_fee' => [
                'eiv' => round($eiv, 2),
                'sci' => $sciManufacturing,
                'job_cost' => round($jobCost, 2),
                'tax_rate' => $taxRate,
                'facility_tax' => round($facilityTax, 2),
                'total' => round($mainBuildFee, 2),
                'components_fee' => round($componentsFee, 2),
                'total_full_build' => round($totalBuildFeeFullBuild, 2),
                'has_data' => $needsFeeCalc,
            ],
            // Job fees plus SCC fee; totals and with_fee values apply even when
            // there is no job fee (then they equal materials + SCC fee).
            'total_fee_full_buy' => round($totalFeeFullBuy, 2),
            'total_fee_full_build' => round($totalFeeFullBuild, 2),
            'total_cost_full_buy_with_fee' => round($totalCostFullBuyWithFee, 2),
            'profit_full_buy_with_fee' => round($profitFullBuyWithFee, 2),
            'margin_full_buy_with_fee' => $marginFullBuyWithFee,
            'per_unit_full_buy_with_fee' => round($perUnitFullBuyWithFee, 2),
            'total_cost_full_build_with_fee' => round($totalCostFullBuildWithFee, 2),
            'profit_full_build_with_fee' => round($profitFullBuildWithFee, 2),
            'margin_full_build_with_fee' => $marginFullBuildWithFee,
            'per_unit_full_build_with_fee' => round($perUnitFullBuildWithFee, 2),
        ];
    }
}
