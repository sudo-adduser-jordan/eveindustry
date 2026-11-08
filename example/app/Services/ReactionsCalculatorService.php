<?php

namespace App\Services;

use App\Models\EveType;

class ReactionsCalculatorService
{
    // Reaction-related skill type IDs
    public const SKILL_REACTIONS = 45746;

    public const SKILL_MASS_REACTIONS = 45748;

    public const SKILL_ADVANCED_MASS_REACTIONS = 45749;

    // Structure types for reactions
    public const STRUCTURE_ATHANOR = 'athanor';

    public const STRUCTURE_TATARA = 'tatara';

    // Rig tiers
    public const RIG_NONE = 'none';

    public const RIG_T1 = 't1';

    public const RIG_T2 = 't2';

    // Security space
    public const SECURITY_LOWSEC = 'lowsec';

    public const SECURITY_NULLSEC_WH = 'nullsec';

    // Base rig ME bonus percentages (before security multiplier)
    public const RIG_ME_BONUS = [
        self::RIG_NONE => 0.0,
        self::RIG_T1 => 2.0,
        self::RIG_T2 => 2.4,
    ];

    // Security space multipliers for rig bonuses
    public const SECURITY_MULTIPLIER = [
        self::SECURITY_LOWSEC => 1.0,
        self::SECURITY_NULLSEC_WH => 1.1,
    ];

    /**
     * Resolve the effective rig ME bonus from structure settings.
     *
     * Formula: effective_me = base_rig_me × security_multiplier
     * e.g. T2 rig in nullsec/WH = 2.4% × 1.1 = 2.64%
     */
    public static function resolveRigMeBonus(string $rigTier = 'none', string $securitySpace = 'lowsec'): float
    {
        $baseMe = self::RIG_ME_BONUS[$rigTier] ?? 0.0;
        $secMult = self::SECURITY_MULTIPLIER[$securitySpace] ?? 1.0;

        return $baseMe * $secMult;
    }

    public function __construct(
        protected EsiService $esi,
        protected MarketService $marketService
    ) {}

    /**
     * Search for reaction formulas by name using the local eve_types table.
     * Falls back to ESI search if no local results are found.
     */
    public function searchReactions(string $query): array
    {
        // Search the local database first for fast typeahead
        $escapedQuery = addcslashes($query, '%_');
        $types = EveType::where('name', 'like', '%'.$escapedQuery.'%')
            ->where('name', 'like', '%Reaction Formula%')
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
            if (isset($typeInfo['published']) && $typeInfo['published']) {
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
     * Calculate reaction material requirements.
     *
     * Reactions have a fixed ME of 0 (no ME research possible).
     * Rigged refineries can reduce material costs by 2.4% (T1 rig) or 2.88% (T2 rig).
     */
    public function calculateMaterials(array $baseMaterials, int $runs, float $rigBonus = 0.0): array
    {
        $materials = [];

        foreach ($baseMaterials as $material) {
            $baseQty = $material['quantity'] ?? 0;
            $typeId = $material['type_id'] ?? 0;

            // Structure rig reduction
            $rigFactor = 1.0 - ($rigBonus / 100.0);
            $adjustedQty = max($runs, (int) ceil(round($baseQty * $runs * $rigFactor, 2)));

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
     * Calculate reaction production time.
     *
     * Base reaction time is typically fixed. Reductions:
     * - Reactions skill: 4% per level
     * - Structure TE rig bonus
     */
    public function calculateProductionTime(
        int $baseTimeSeconds,
        int $runs,
        int $reactionsSkill = 0,
        float $structureTeBonus = 0.0
    ): array {
        // Reactions skill: 4% per level
        $reactionsFactor = 1.0 - ($reactionsSkill * 4 / 100.0);
        // Structure bonus
        $structureFactor = 1.0 - ($structureTeBonus / 100.0);

        $timePerRun = $baseTimeSeconds * $reactionsFactor * $structureFactor;
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
     * Calculate cost/profit for a reaction job.
     */
    public function calculateCostProfit(
        array $materials,
        int $productTypeId,
        int $runs,
        int $portionSize = 1,
        int $regionId = MarketService::DEFAULT_REGION_ID
    ): array {
        $materialTypeIds = array_column($materials, 'type_id');
        $materialTypeIds[] = $productTypeId;

        $prices = $this->marketService->getSellPricesWithSource($materialTypeIds, $regionId);

        $totalMaterialCost = 0;
        $materialCosts = [];

        foreach ($materials as $material) {
            $typeId = $material['type_id'];
            $unitPrice = $prices[$typeId]['price'] ?? 0;
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

        $productSellPrice = $prices[$productTypeId]['price'] ?? 0;
        $totalOutput = $runs * $portionSize;
        $totalRevenue = $productSellPrice * $totalOutput;

        // Profit after the SCC fee, margin on input cost (same as manufacturing)
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
