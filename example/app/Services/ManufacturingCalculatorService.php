<?php

namespace App\Services;

use App\Models\EveType;

class ManufacturingCalculatorService
{
    // Manufacturing-related skill type IDs
    public const SKILL_INDUSTRY = 3380;

    public const SKILL_ADVANCED_INDUSTRY = 3388;

    public const SKILL_ADVANCED_SMALL_SHIP_CONSTRUCTION = 22242;

    public const SKILL_ADVANCED_MEDIUM_SHIP_CONSTRUCTION = 22243;

    public const SKILL_ADVANCED_LARGE_SHIP_CONSTRUCTION = 22244;

    public const SKILL_ADVANCED_INDUSTRIAL_SHIP_CONSTRUCTION = 22245;

    // Structure role bonuses (percentage)
    public const RAITARU_ME_BONUS = 1.0;

    public const AZBEL_ME_BONUS = 1.0;

    public const SOTIYO_ME_BONUS = 1.0;

    public const RAITARU_TE_BONUS = 3.0;

    public const AZBEL_TE_BONUS = 3.0;

    public const SOTIYO_TE_BONUS = 3.0;

    // Manufacturing rig ME bonuses (base percentage, before security multiplier)
    public const RIG_ME_T1 = 2.0;

    public const RIG_ME_T2 = 2.4;

    // Manufacturing rig TE bonuses (base percentage, before security multiplier)
    public const RIG_TE_T1 = 20.0;

    public const RIG_TE_T2 = 24.0;

    // Security space multipliers for manufacturing rig bonuses
    public const SECURITY_MULTIPLIER_HIGHSEC = 1.0;

    public const SECURITY_MULTIPLIER_LOWSEC = 1.9;

    public const SECURITY_MULTIPLIER_NULLSEC = 2.1;

    // Security space multipliers for reaction rig bonuses
    public const REACTION_SECURITY_MULTIPLIER_HIGHSEC = 1.0;

    public const REACTION_SECURITY_MULTIPLIER_LOWSEC = 1.0;

    public const REACTION_SECURITY_MULTIPLIER_NULLSEC = 1.1;

    // SCC (Secure Commerce Commission) fee rate; see IndustryProfitCalculator
    public const SCC_FEE_RATE = IndustryProfitCalculator::SCC_FEE_RATE;

    public function __construct(
        protected EsiService $esi,
        protected MarketService $marketService
    ) {}

    /**
     * Combine multiple material-efficiency (ME) bonus percentages the way EVE does:
     * each bonus applies as an independent (1 - bonus/100) multiplier, not as a sum.
     * Returns the effective combined bonus as a percentage.
     *
     * Example: role 1% and rig 3.8% combine to
     * (1 - (1 - 0.01)(1 - 0.038)) * 100 ≈ 4.762%, not 4.8%.
     */
    public static function combineMeBonuses(float ...$bonusPercents): float
    {
        $factor = 1.0;

        foreach ($bonusPercents as $bonus) {
            $factor *= 1.0 - ($bonus / 100.0);
        }

        return (1.0 - $factor) * 100.0;
    }

    /**
     * Search for blueprints by name using the local eve_types table.
     * Falls back to ESI search if no local results are found.
     */
    public function searchBlueprints(string $query): array
    {
        // Search the local database first for fast typeahead
        $escapedQuery = addcslashes($query, '%_');
        $types = EveType::where('name', 'like', '%'.$escapedQuery.'%')
            ->where('name', 'like', '%Blueprint%')
            ->where('published', true)
            ->orderBy('name')
            ->limit(20)
            ->get();

        if ($types->isNotEmpty()) {
            return $types->map(fn (EveType $type) => [
                'type_id' => $type->type_id,
                'name' => $type->name,
                'group_id' => $type->group_id,
                'icon_url' => EsiService::getTypeImageUrl($type->type_id, 'icon', 32),
            ])->values()->toArray();
        }

        // Fall back to ESI search if the local table is empty or has no matches
        $searchResult = $this->esi->searchTypes($query);
        if (! $searchResult || empty($searchResult['inventory_type'])) {
            return [];
        }

        $results = [];
        $typeIds = array_slice($searchResult['inventory_type'], 0, 50);

        foreach ($typeIds as $typeId) {
            $typeInfo = $this->esi->getTypeInfo($typeId);
            if (! $typeInfo) {
                continue;
            }

            $name = $typeInfo['name'] ?? '';
            if (
                str_contains(strtolower($name), 'blueprint') ||
                (isset($typeInfo['published']) && $typeInfo['published'])
            ) {
                $results[] = [
                    'type_id' => $typeId,
                    'name' => $name,
                    'group_id' => $typeInfo['group_id'] ?? null,
                    'icon_url' => EsiService::getTypeImageUrl($typeId, 'icon', 32),
                ];
            }
        }

        return $results;
    }

    /**
     * Calculate material requirements with ME level applied.
     *
     * Materials are reduced by ME percentage. The formula is:
     * adjusted_quantity = max(runs, ceil(round(base_quantity * runs * (1 - ME/100) * structure_bonus, 2)))
     */
    public function calculateMaterials(array $baseMaterials, int $meLevel, int $runs, float $structureMeBonus = 0.0): array
    {
        $materials = [];

        foreach ($baseMaterials as $material) {
            $baseQty = $material['quantity'] ?? 0;
            $typeId = $material['type_id'] ?? 0;

            // ME reduction: 1 - (ME / 100)
            $meFactor = 1.0 - ($meLevel / 100.0);
            // Structure bonus reduction
            $structureFactor = 1.0 - ($structureMeBonus / 100.0);

            $adjustedQty = max($runs, (int) ceil(round($baseQty * $runs * $meFactor * $structureFactor, 2)));

            $materials[] = [
                'type_id' => $typeId,
                'name' => $material['name'] ?? 'Unknown',
                'base_quantity' => $baseQty,
                'adjusted_quantity' => $adjustedQty,
                'quantity_per_run' => $runs > 0 ? ceil($adjustedQty / $runs) : $adjustedQty,
            ];
        }

        return $materials;
    }

    /**
     * Calculate production time with TE level and skills applied.
     *
     * Base time is in seconds. Reductions:
     * - TE level: 1% per point (0–20 range, up to 20% at TE 20)
     * - Industry skill: 4% per level
     * - Advanced Industry: 3% per level
     * - Structure TE bonus
     */
    public function calculateProductionTime(
        int $baseTimeSeconds,
        int $teLevel,
        int $runs,
        int $industrySkill = 0,
        int $advancedIndustrySkill = 0,
        float $structureTeBonus = 0.0
    ): array {
        // TE reduction: TE ranges from 0–20, each point = 1% reduction
        $teFactor = 1.0 - ($teLevel / 100.0);
        // Industry skill: 4% per level
        $industryFactor = 1.0 - ($industrySkill * 4 / 100.0);
        // Advanced Industry: 3% per level
        $advIndustryFactor = 1.0 - ($advancedIndustrySkill * 3 / 100.0);
        // Structure bonus
        $structureFactor = 1.0 - ($structureTeBonus / 100.0);

        $timePerRun = $baseTimeSeconds * $teFactor * $industryFactor * $advIndustryFactor * $structureFactor;
        $totalTime = $timePerRun * $runs;

        return [
            'base_time_seconds' => $baseTimeSeconds,
            'time_per_run_seconds' => (int) round($timePerRun),
            'total_time_seconds' => (int) round($totalTime),
            'time_per_run_formatted' => $this->formatDuration((int) round($timePerRun)),
            'total_time_formatted' => $this->formatDuration((int) round($totalTime)),
        ];
    }

    /**
     * Calculate full cost/profit for a manufacturing job.
     */
    public function calculateCostProfit(
        array $materials,
        int $productTypeId,
        int $runs,
        int $portionSize = 1,
        int $regionId = MarketService::DEFAULT_REGION_ID,
        ?float $buybackPercentage = null
    ): array {
        // Get all material type IDs
        $materialTypeIds = array_column($materials, 'type_id');
        $materialTypeIds[] = $productTypeId;

        // Same price source as the manufacturing browser and precomputed costs
        $prices = $this->marketService->getSellPricesWithSource($materialTypeIds, $regionId);

        // Buyback multiplier: e.g. 90% buyback → multiply prices by 0.90
        $buybackMultiplier = ($buybackPercentage !== null) ? ($buybackPercentage / 100.0) : 1.0;

        // Calculate material cost
        $totalMaterialCost = 0;
        $materialCosts = [];

        foreach ($materials as $material) {
            $typeId = $material['type_id'];
            $baseUnitPrice = $prices[$typeId]['price'] ?? 0;
            $unitPrice = $baseUnitPrice * $buybackMultiplier;
            $totalCost = $unitPrice * $material['adjusted_quantity'];
            $totalMaterialCost += $totalCost;

            $materialCosts[] = array_merge($material, [
                'unit_price' => $unitPrice,
                'total_cost' => $totalCost,
                'unit_price_formatted' => MarketService::formatIsk($unitPrice),
                'total_cost_formatted' => MarketService::formatIsk($totalCost),
                'price_source' => $prices[$typeId]['source'] ?? MarketService::PRICE_SOURCE_NONE,
            ]);
        }

        // Calculate product value
        $productSellPrice = $prices[$productTypeId]['price'] ?? 0;
        $totalOutput = $runs * $portionSize;
        $totalRevenue = $productSellPrice * $totalOutput;

        // Profit after the SCC fee, margin on input cost
        $summary = IndustryProfitCalculator::summary($totalRevenue, $totalMaterialCost);
        $profit = $summary['profit'];
        $profitPerUnit = $totalOutput > 0 ? $profit / $totalOutput : 0;
        $profitMargin = $summary['profit_margin'];

        return [
            'materials' => $materialCosts,
            'total_material_cost' => $totalMaterialCost,
            'total_material_cost_formatted' => MarketService::formatIsk($totalMaterialCost),
            'product_sell_price' => $productSellPrice,
            'product_sell_price_formatted' => MarketService::formatIsk($productSellPrice),
            'product_price_source' => $prices[$productTypeId]['source'] ?? MarketService::PRICE_SOURCE_NONE,
            'scc_fee' => $summary['scc_fee'],
            'total_output' => $totalOutput,
            'total_revenue' => $totalRevenue,
            'total_revenue_formatted' => MarketService::formatIsk($totalRevenue),
            'profit' => $profit,
            'profit_formatted' => MarketService::formatIsk(abs($profit)),
            'profit_positive' => $profit >= 0,
            'profit_per_unit' => $profitPerUnit,
            'profit_per_unit_formatted' => MarketService::formatIsk(abs($profitPerUnit)),
            'profit_margin' => $profitMargin,
        ];
    }

    /**
     * Format seconds into a human-readable duration.
     */
    public function formatDuration(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.'d';
        }
        if ($hours > 0 || $days > 0) {
            $parts[] = $hours.'h';
        }
        if ($minutes > 0 || $hours > 0 || $days > 0) {
            $parts[] = $minutes.'m';
        }
        $parts[] = $secs.'s';

        return implode(' ', $parts);
    }
}
