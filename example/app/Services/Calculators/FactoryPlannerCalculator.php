<?php

namespace App\Services\Calculators;

use App\Services\FactoryPlanetPlannerService;

/**
 * Factory planet profitability for one tier, after customs tax. Shared by
 * GET /api/planetary/factory-planner/calculate and
 * GET /api/v1/calculators/factory-planner.
 */
class FactoryPlannerCalculator
{
    public function __construct(protected FactoryPlanetPlannerService $planner) {}

    /**
     * @param  string  $tier  A key of FactoryPlanetPlannerService::TIER_LABELS
     * @param  float  $taxRate  Customs tax percentage, already within 0–100
     * @return array{tier: string, tier_label: string, factory_slots: int, tax_rate: float, results: array<int, array<string, mixed>>}
     */
    public function calculate(string $tier, int $regionId, float $taxRate): array
    {
        $results = $this->planner->applyCustomsTax(
            $this->planner->calculateProfitability($tier, $regionId),
            $tier,
            $taxRate
        );

        return [
            'tier' => $tier,
            'tier_label' => FactoryPlanetPlannerService::TIER_LABELS[$tier],
            'factory_slots' => FactoryPlanetPlannerService::FACTORY_SLOTS,
            'tax_rate' => $taxRate,
            'results' => $results,
        ];
    }
}
