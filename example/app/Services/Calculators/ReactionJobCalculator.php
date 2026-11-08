<?php

namespace App\Services\Calculators;

use App\Services\EsiService;
use App\Services\MarketService;
use App\Services\ReactionsCalculatorService;

/**
 * One reaction job: materials, time and cost/profit.
 *
 * Shared by POST /api/calculators/reactions/calculate and
 * POST /api/v1/calculators/reactions. calculate() returns the old route's
 * body without "success".
 */
class ReactionJobCalculator
{
    public function __construct(
        protected ReactionsCalculatorService $calculator,
        protected MarketService $marketService,
        protected EsiService $esi,
    ) {}

    /**
     * @param  array<string, mixed>  $validated  Input validated with the calculate rules
     * @return array<string, mixed>
     *
     * @throws CalculationException when the formula has no materials
     */
    public function calculate(array $validated): array
    {
        $runs = $validated['runs'];
        $rigBonus = $validated['rig_bonus'] ?? 0;
        $structureTeBonus = $validated['structure_te_bonus'] ?? 0;
        $portionSize = $validated['portion_size'] ?? 1;
        $regionId = $validated['region_id'] ?? MarketService::DEFAULT_REGION_ID;

        // Auto-resolve materials from SDE if not provided by frontend
        $baseMaterials = $validated['materials'] ?? [];
        $baseTime = $validated['base_time'] ?? 0;

        if (empty($baseMaterials) || ! $baseTime) {
            $sdeData = $this->esi->resolveBlueprintActivity($validated['formula_type_id'], 11);
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
            throw new CalculationException('No materials found for this reaction formula.', 'formula_type_id');
        }

        // Calculate materials
        $materials = $this->calculator->calculateMaterials(
            $baseMaterials,
            $runs,
            $rigBonus
        );

        // Calculate time
        $time = $this->calculator->calculateProductionTime(
            $baseTime,
            $runs,
            $validated['reactions_skill'],
            $structureTeBonus
        );

        // Ensure material prices are in database
        $typeIds = array_column($materials, 'type_id');
        $typeIds[] = $validated['product_type_id'];
        $this->marketService->fetchAndStoreRegionalPrices($typeIds, $regionId);

        // Calculate cost/profit
        $costProfit = $this->calculator->calculateCostProfit(
            $materials,
            $validated['product_type_id'],
            $runs,
            $portionSize,
            $regionId
        );

        return [
            'materials' => $costProfit['materials'],
            'time' => $time,
            'cost_profit' => $costProfit,
        ];
    }
}
