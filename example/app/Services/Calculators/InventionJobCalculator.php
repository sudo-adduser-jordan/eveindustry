<?php

namespace App\Services\Calculators;

use App\Models\EveType;
use App\Services\InventionPlannerService;
use App\Services\MarketService;

/**
 * The full invention + T2 manufacturing chain: invention probability and
 * cost, T2 BPC, T2 materials with buildable components, job fees and the
 * full-buy / full-build profit scenarios.
 *
 * Shared by POST /api/calculators/invention-planner/calculate and
 * POST /api/v1/calculators/invention. calculate() returns the old route's
 * body without "success".
 */
class InventionJobCalculator
{
    public function __construct(
        protected InventionPlannerService $planner,
        protected MarketService $marketService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Input validated with the calculate rules
     * @return array<string, mixed>
     *
     * @throws CalculationException when the invention chain cannot be resolved
     */
    public function calculate(array $validated): array
    {
        // Resolve chain
        $chain = $this->planner->resolveInventionChain($validated['t2_type_id']);
        if (! $chain) {
            throw new CalculationException('Could not resolve invention chain', 't2_type_id');
        }

        $decryptorKey = $validated['decryptor_key'] ?? 'none';
        $decryptor = InventionPlannerService::DECRYPTORS[$decryptorKey] ?? InventionPlannerService::DECRYPTORS['none'];

        $baseProbability = (float) ($validated['base_probability'] ?? $chain['base_probability']);
        $baseRuns = (int) ($validated['base_runs'] ?? $chain['base_runs']);
        $inventionRuns = $validated['invention_runs'];

        $encryptionSkill = $validated['encryption_skill'];
        $science1Skill = $validated['science1_skill'];
        $science2Skill = $validated['science2_skill'];

        // Calculate invention probability
        $probability = $this->planner->calculateInventionProbability(
            $baseProbability,
            $encryptionSkill,
            $science1Skill,
            $science2Skill,
            $decryptor['probability_multiplier']
        );

        // Calculate T2 BPC output
        $t2Bpc = $this->planner->calculateT2Bpc($baseRuns, $decryptorKey);

        // Calculate structure bonuses
        $structureType = $validated['structure_type'] ?? 'none';
        $rigMe = $validated['rig_me'] ?? 'none';
        $rigTe = $validated['rig_te'] ?? 'none';
        $systemSecurity = $validated['system_security'] ?? 'highsec';

        $structureMeBonus = StructureBonuses::manufacturingMe($structureType, $rigMe, $systemSecurity);
        $structureTeBonus = StructureBonuses::manufacturingTe($structureType, $rigTe, $systemSecurity);

        // Calculate reaction structure bonuses (for reaction sub-components)
        $reactionStructureType = $validated['reaction_structure_type'] ?? null;
        $reactionMeBonus = null;
        if ($reactionStructureType !== null) {
            $reactionRigMe = $validated['reaction_rig_me'] ?? 'none';
            $reactionSystemSecurity = $validated['reaction_system_security'] ?? 'lowsec';
            $reactionMeBonus = StructureBonuses::reactionMe($reactionRigMe, $reactionSystemSecurity);
        }

        // Calculate T2 materials (using invented BPC ME)
        $t2MeLevel = $t2Bpc['me'];
        $t2BpcRuns = $t2Bpc['runs'];
        $t2Materials = $this->planner->calculateMaterials(
            $chain['t2_manufacturing']['materials'],
            $t2MeLevel,
            $t2BpcRuns * $inventionRuns,
            $structureMeBonus
        );

        // Resolve component build data for T2 materials (assume ME10 blueprints for all components)
        $componentBuildData = $this->planner->resolveComponentBuildData($t2Materials, 10, $structureMeBonus, $reactionMeBonus);

        // Collect all type IDs for price lookup
        $allTypeIds = [];
        foreach ($t2Materials as $mat) {
            $allTypeIds[] = $mat['type_id'];
        }
        foreach ($chain['datacores'] as $dc) {
            $allTypeIds[] = $dc['type_id'];
        }
        if ($decryptor['type_id']) {
            $allTypeIds[] = $decryptor['type_id'];
        }
        $allTypeIds[] = $chain['t2_item_type_id'];
        $allTypeIds[] = $chain['t1_item_type_id'];

        // Also collect type IDs from component sub-materials
        foreach ($componentBuildData as $compData) {
            if ($compData && ! empty($compData['materials'])) {
                foreach ($compData['materials'] as $subMat) {
                    $allTypeIds[] = $subMat['type_id'];
                }
            }
        }
        $allTypeIds = array_values(array_unique(array_filter($allTypeIds)));

        $priceRegionId = (int) ($validated['price_region_id'] ?? MarketService::DEFAULT_REGION_ID);
        $sellPrices = $this->marketService->getSellPrices($allTypeIds, $priceRegionId);

        // Batch-fetch volumes for all type IDs (packaged_volume preferred, fallback to volume)
        $volumes = EveType::whereIn('type_id', $allTypeIds)
            ->get(['type_id', 'volume', 'packaged_volume'])
            ->mapWithKeys(fn ($t) => [$t->type_id => $t->packaged_volume ?? $t->volume ?? 0])
            ->all();

        // Job fee calculation (needed for component build costs)
        $systemCostIndex = (float) ($validated['system_cost_index'] ?? 0);
        $facilityTaxRate = (float) ($validated['facility_tax'] ?? 0);

        // Attach prices to T2 materials and component build data
        $totalT2MaterialCost = 0;
        $totalT2BuildCost = 0;
        foreach ($t2Materials as &$mat) {
            $unitPrice = $sellPrices[$mat['type_id']] ?? 0;
            $mat['unit_price'] = $unitPrice;
            $mat['total_cost'] = $unitPrice * $mat['adjusted_quantity'];
            $mat['volume'] = $volumes[$mat['type_id']] ?? 0;
            $totalT2MaterialCost += $mat['total_cost'];

            // Attach component build data if available
            $compData = $componentBuildData[$mat['type_id']] ?? null;
            if ($compData) {
                $buildCost = 0;
                foreach ($compData['materials'] as &$subMat) {
                    $subPrice = $sellPrices[$subMat['type_id']] ?? 0;
                    $subMat['unit_price'] = $subPrice;
                    $subMat['total_cost'] = $subPrice * $subMat['adjusted_quantity'];
                    $subMat['volume'] = $volumes[$subMat['type_id']] ?? 0;
                    $buildCost += $subMat['total_cost'];
                }
                unset($subMat);

                // Component job fee
                $compJobFee = $buildCost * ($systemCostIndex / 100);
                $compFacilityTax = $compJobFee * ($facilityTaxRate / 100);
                $compTotalJobCost = $compJobFee + $compFacilityTax;

                $componentBuildTotal = round($buildCost + $compTotalJobCost, 2);

                $mat['component'] = [
                    'is_buildable' => true,
                    'blueprint_type_id' => $compData['blueprint_type_id'],
                    'portion_size' => $compData['portion_size'],
                    'runs_needed' => $compData['runs_needed'],
                    'base_time' => $compData['base_time'],
                    'materials' => $compData['materials'],
                    'build_cost' => $componentBuildTotal,
                    'material_cost' => round($buildCost, 2),
                    'job_cost' => round($compTotalJobCost, 2),
                ];

                // Use build cost for buildable components
                $totalT2BuildCost += $componentBuildTotal;
            } else {
                $mat['component'] = null;
                // Use buy cost for non-buildable materials
                $totalT2BuildCost += $mat['total_cost'];
            }
        }
        unset($mat);

        // Datacore costs (per invention attempt)
        $datacoreCostPerAttempt = 0;
        $datacoresWithPrices = [];
        foreach ($chain['datacores'] as $dc) {
            $unitPrice = $sellPrices[$dc['type_id']] ?? 0;
            $totalCost = $unitPrice * $dc['quantity'];
            $datacoreCostPerAttempt += $totalCost;
            $datacoresWithPrices[] = array_merge($dc, [
                'unit_price' => $unitPrice,
                'total_cost' => $totalCost,
                'volume' => $volumes[$dc['type_id']] ?? 0,
            ]);
        }

        // Decryptor cost per attempt
        $decryptorUnitPrice = 0;
        $decryptorVolume = 0;
        if ($decryptor['type_id']) {
            $decryptorUnitPrice = $sellPrices[$decryptor['type_id']] ?? 0;
            $decryptorVolume = $volumes[$decryptor['type_id']] ?? 0;
        }
        $inventionCostPerAttempt = $datacoreCostPerAttempt + $decryptorUnitPrice;

        // Expected attempts to get one success (ceiled — you can't do a fractional attempt)
        $expectedAttempts = $probability > 0 ? (int) ceil(1.0 / $probability) : 0;
        $inventionCostPerSuccess = $probability > 0 ? $inventionCostPerAttempt / $probability : 0;

        // Total invention cost for the planned number of runs
        $totalInventionCost = $inventionCostPerAttempt * $inventionRuns * $expectedAttempts;

        // Shopping list for all planned attempts: whole units to buy and their cost
        $totalAttempts = $expectedAttempts * $inventionRuns;
        foreach ($datacoresWithPrices as &$dc) {
            $dc['total_quantity'] = (int) ceil($dc['quantity'] * $totalAttempts);
            $dc['total_quantity_cost'] = $dc['unit_price'] * $dc['total_quantity'];
        }
        unset($dc);
        $decryptorTotalQuantity = $decryptorUnitPrice > 0 ? (int) ceil($totalAttempts) : 0;
        $decryptorTotalCost = $decryptorUnitPrice * $decryptorTotalQuantity;

        // T2 manufacturing job cost
        $t2JobFee = $totalT2MaterialCost * ($systemCostIndex / 100);
        $t2FacilityTax = $t2JobFee * ($facilityTaxRate / 100);
        $t2TotalJobCost = $t2JobFee + $t2FacilityTax;

        // Product value
        $t2SellPrice = $sellPrices[$chain['t2_item_type_id']] ?? 0;
        $t2PortionSize = 1;
        if (! empty($chain['t2_manufacturing']['products'])) {
            $t2PortionSize = $chain['t2_manufacturing']['products'][0]['quantity'] ?? 1;
        }
        $totalOutput = $t2BpcRuns * $inventionRuns * $t2PortionSize;
        $totalOutputValue = $t2SellPrice * $totalOutput;

        // Full Buy scenario: buy all T2 materials at market price
        $totalCostFullBuy = $totalT2MaterialCost + $totalInventionCost + $t2TotalJobCost;
        $profitFullBuy = $totalOutputValue - $totalCostFullBuy;
        $marginFullBuy = $totalCostFullBuy > 0 ? round($profitFullBuy / $totalCostFullBuy * 100, 1) : 0;
        $perUnitFullBuy = $totalOutput > 0 ? $profitFullBuy / $totalOutput : 0;

        // Full Build scenario: build all buildable components at ME10
        $totalCostFullBuild = $totalT2BuildCost + $totalInventionCost + $t2TotalJobCost;
        $profitFullBuild = $totalOutputValue - $totalCostFullBuild;
        $marginFullBuild = $totalCostFullBuild > 0 ? round($profitFullBuild / $totalCostFullBuild * 100, 1) : 0;
        $perUnitFullBuild = $totalOutput > 0 ? $profitFullBuild / $totalOutput : 0;

        // Use cheaper option for top-level profit summary
        $buildCheaper = $totalCostFullBuild < $totalCostFullBuy;
        $totalCost = $buildCheaper ? $totalCostFullBuild : $totalCostFullBuy;
        $profit = $totalOutputValue - $totalCost;
        $profitMargin = $totalCost > 0 ? round($profit / $totalCost * 100, 1) : 0;
        $profitPerUnit = $totalOutput > 0 ? $profit / $totalOutput : 0;

        return [
            'probability' => $probability,
            'expected_attempts' => $expectedAttempts,
            'invention_cost_per_attempt' => $inventionCostPerAttempt,
            'invention_cost_per_success' => round($inventionCostPerSuccess, 2),
            'total_invention_cost' => round($totalInventionCost, 2),
            't2_bpc' => $t2Bpc,
            't2_materials' => $t2Materials,
            'total_t2_material_cost' => $totalT2MaterialCost,
            'total_t2_build_cost' => round($totalT2BuildCost, 2),
            't1_item_type_id' => $chain['t1_item_type_id'],
            't1_item_name' => $chain['t1_item_name'],
            'datacores' => $datacoresWithPrices,
            'datacore_cost_per_attempt' => $datacoreCostPerAttempt,
            'decryptor_unit_price' => $decryptorUnitPrice,
            'decryptor_volume' => $decryptorVolume,
            'total_attempts' => $totalAttempts,
            'decryptor_total_quantity' => $decryptorTotalQuantity,
            'decryptor_total_cost' => $decryptorTotalCost,
            't2_job_fee' => $t2JobFee,
            't2_facility_tax' => $t2FacilityTax,
            't2_total_job_cost' => $t2TotalJobCost,
            'total_output' => $totalOutput,
            'total_output_value' => $totalOutputValue,
            't2_sell_price' => $t2SellPrice,
            'total_cost' => round($totalCost, 2),
            'profit' => round($profit, 2),
            'profit_margin' => $profitMargin,
            'profit_per_unit' => round($profitPerUnit, 2),
            'build_cheaper' => $buildCheaper,
            'total_cost_full_buy' => round($totalCostFullBuy, 2),
            'profit_full_buy' => round($profitFullBuy, 2),
            'margin_full_buy' => $marginFullBuy,
            'per_unit_full_buy' => round($perUnitFullBuy, 2),
            'total_cost_full_build' => round($totalCostFullBuild, 2),
            'profit_full_build' => round($profitFullBuild, 2),
            'margin_full_build' => $marginFullBuild,
            'per_unit_full_build' => round($perUnitFullBuild, 2),
            'structure_me_bonus' => round($structureMeBonus, 2),
            'structure_te_bonus' => round($structureTeBonus, 2),
        ];
    }
}
