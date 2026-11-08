<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\MarketHistory;

class ReactionBrowserService
{
    /** EVE SDE group IDs for reaction formula categories */
    public const GROUP_COMPOSITE_REACTIONS = 1888;

    public const GROUP_BIOCHEMICAL_REACTIONS = 1889;

    public const GROUP_HYBRID_REACTIONS = 4097;

    public function __construct(
        protected ReactionsCalculatorService $calculator,
        protected MarketService $marketService,
        protected EsiService $esi
    ) {}

    /**
     * Fetch all reaction formulas from the local blueprint_activities table.
     *
     * Returns an array of reactions with their SDE data (materials, products, time).
     *
     * @return array<int, array{blueprint_type_id: int, formula_name: string, materials: array, products: array, time: int}>
     */
    public function getAllReactions(): array
    {
        $activities = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_REACTION)
            ->whereNotNull('products')
            ->get();

        // Build a set of all product type IDs to determine complexity
        $reactionProductTypeIds = $this->getReactionProductTypeIds($activities);

        $reactions = [];

        foreach ($activities as $activity) {
            $products = $activity->products ?? [];
            if (empty($products)) {
                continue;
            }

            $formulaType = EveType::find($activity->blueprint_type_id);
            $formulaName = $formulaType?->name ?? 'Unknown Formula';
            $materials = $activity->materials ?? [];

            $reactions[] = [
                'blueprint_type_id' => $activity->blueprint_type_id,
                'formula_name' => $formulaName,
                'reaction_category' => self::resolveReactionCategory($formulaType?->group_id),
                'reaction_complexity' => self::resolveReactionComplexity($materials, $reactionProductTypeIds),
                'materials' => $materials,
                'products' => $products,
                'time' => $activity->time ?? 0,
            ];
        }

        usort($reactions, fn ($a, $b) => strcmp($a['formula_name'], $b['formula_name']));

        return $reactions;
    }

    /**
     * Calculate profitability for all reactions given user parameters.
     *
     * @param  int  $runs  Number of runs
     * @param  float  $rigBonus  Effective rig ME bonus percentage
     * @param  float  $facilityTaxRate  Facility tax rate percentage (0-50)
     * @param  float  $systemCostIndex  System cost index percentage (0-100)
     * @param  int  $regionId  Market region for price lookups
     * @return array List of reactions with profitability data
     */
    public function calculateAllProfitability(
        int $runs = 1,
        float $rigBonus = 0.0,
        float $facilityTaxRate = 0.0,
        float $systemCostIndex = 0.0,
        int $regionId = MarketService::DEFAULT_REGION_ID
    ): array {
        $reactions = $this->getAllReactions();
        if (empty($reactions)) {
            return [];
        }

        // Collect all type IDs needed for price lookups
        $allTypeIds = [];
        foreach ($reactions as $reaction) {
            foreach ($reaction['materials'] as $mat) {
                $allTypeIds[] = $mat['type_id'];
            }
            foreach ($reaction['products'] as $prod) {
                $allTypeIds[] = $prod['type_id'];
            }
        }
        $allTypeIds = array_values(array_unique($allTypeIds));

        // Use Jita lowest sell prices (orders → history → market_prices fallback)
        $sellPrices = $this->marketService->getSellPrices($allTypeIds, $regionId);

        $results = [];

        foreach ($reactions as $reaction) {
            $products = $reaction['products'];
            $productTypeId = $products[0]['type_id'] ?? null;
            $productName = $products[0]['name'] ?? 'Unknown';
            $portionSize = $products[0]['quantity'] ?? 1;

            if (! $productTypeId) {
                continue;
            }

            // Calculate adjusted materials
            $materials = $this->calculator->calculateMaterials(
                $reaction['materials'],
                $runs,
                $rigBonus
            );

            // Calculate input cost using Jita sell prices
            $totalInputCost = 0;
            foreach ($materials as $material) {
                $unitPrice = $sellPrices[$material['type_id']] ?? 0;
                $totalInputCost += $unitPrice * $material['adjusted_quantity'];
            }

            // Calculate output value
            $totalOutput = $runs * $portionSize;
            $productUnitPrice = $sellPrices[$productTypeId] ?? 0;
            $totalOutputValue = $productUnitPrice * $totalOutput;

            // Job fee calculation (same formula as reaction planner)
            ['job_fee' => $jobFee, 'facility_tax' => $facilityTax, 'total_job_cost' => $totalJobCost] = IndustryProfitCalculator::jobCost($totalInputCost, $systemCostIndex, $facilityTaxRate);

            // SCC fee, profit and margin on the same basis as manufacturing
            $summary = IndustryProfitCalculator::summary($totalOutputValue, $totalInputCost, $totalJobCost);
            $sccFee = $summary['scc_fee'];
            $profit = $summary['profit'];
            $profitMargin = $summary['profit_margin'];
            $profitPerUnit = $totalOutput > 0 ? $profit / $totalOutput : 0;

            $sellScore = $this->calculateSellScore($productTypeId, $totalOutput, $regionId);

            $results[] = [
                'blueprint_type_id' => $reaction['blueprint_type_id'],
                'formula_name' => $reaction['formula_name'],
                'reaction_category' => $reaction['reaction_category'],
                'reaction_complexity' => $reaction['reaction_complexity'],
                'product_type_id' => $productTypeId,
                'product_name' => $productName,
                'product_icon_url' => EsiService::getTypeImageUrl($productTypeId, 'icon', 32),
                'portion_size' => $portionSize,
                'total_output' => $totalOutput,
                'total_input_cost' => $totalInputCost,
                'total_output_value' => $totalOutputValue,
                'job_fee' => $jobFee,
                'facility_tax' => $facilityTax,
                'total_job_cost' => $totalJobCost,
                'scc_fee' => $sccFee,
                'profit' => $profit,
                'profit_formatted' => MarketService::formatIsk(abs($profit)),
                'profit_positive' => $profit >= 0,
                'profit_per_unit' => $profitPerUnit,
                'profit_per_unit_formatted' => MarketService::formatIsk(abs($profitPerUnit)),
                'profit_margin' => $profitMargin,
                'total_input_cost_formatted' => MarketService::formatIsk($totalInputCost),
                'total_output_value_formatted' => MarketService::formatIsk($totalOutputValue),
                'total_job_cost_formatted' => MarketService::formatIsk($totalJobCost),
                'sell_score' => $sellScore['score'],
                'sell_score_avg_daily_volume' => $sellScore['avg_daily_volume'],
                'sell_score_avg_order_count' => $sellScore['avg_order_count'],
                'sell_score_active_days' => $sellScore['active_days'],
                'sell_score_total_days' => $sellScore['total_days'],
            ];
        }

        // Sort by profit descending by default
        usort($results, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return $results;
    }

    /**
     * Calculate a sellability score (0–10) for a product based on market history.
     *
     * The score combines three factors:
     *  - Volume ratio (0–6): avg daily volume vs production output
     *  - Market consistency (0–2): proportion of recent days with trades
     *  - Order activity (0–2): average daily order count
     *
     * @return array{score: int, avg_daily_volume: float, avg_order_count: float, active_days: int, total_days: int}
     */
    public function calculateSellScore(int $productTypeId, int $totalOutput, int $regionId = MarketService::DEFAULT_REGION_ID): array
    {
        $history = MarketHistory::where('type_id', $productTypeId)
            ->where('region_id', $regionId)
            ->orderBy('date', 'desc')
            ->take(30)
            ->get();

        $totalDays = $history->count();
        $activeDays = $history->where('volume', '>', 0)->count();
        $avgDailyVolume = $totalDays > 0 ? round($history->avg('volume'), 1) : 0;
        $avgOrderCount = $totalDays > 0 ? round($history->avg('order_count'), 1) : 0;

        // Volume ratio score (0–6): how well daily volume covers production output
        $volumeRatio = $totalOutput > 0 ? $avgDailyVolume / $totalOutput : 0;
        $volumeScore = min(6, round($volumeRatio * 3, 0));

        // Consistency score (0–2): proportion of days with any trades
        $consistencyRatio = $totalDays > 0 ? $activeDays / $totalDays : 0;
        $consistencyScore = round($consistencyRatio * 2, 0);

        // Order count score (0–2): higher order count means more active market
        if ($avgOrderCount >= 20) {
            $orderScore = 2;
        } elseif ($avgOrderCount >= 5) {
            $orderScore = 1;
        } else {
            $orderScore = 0;
        }

        $score = (int) min(10, $volumeScore + $consistencyScore + $orderScore);

        return [
            'score' => $score,
            'avg_daily_volume' => $avgDailyVolume,
            'avg_order_count' => $avgOrderCount,
            'active_days' => $activeDays,
            'total_days' => $totalDays,
        ];
    }

    /**
     * Resolve the reaction category label from an EVE SDE group_id.
     */
    public static function resolveReactionCategory(?int $groupId): string
    {
        return match ($groupId) {
            self::GROUP_COMPOSITE_REACTIONS => 'composite',
            self::GROUP_BIOCHEMICAL_REACTIONS => 'biochemical',
            self::GROUP_HYBRID_REACTIONS => 'hybrid',
            default => 'other',
        };
    }

    /**
     * Determine the complexity of a reaction based on its input materials.
     *
     * A reaction is "complex" if any of its input materials are themselves
     * products of other reactions (i.e., intermediates). Otherwise, it uses
     * only raw materials and is classified as "intermediate".
     *
     * @param  array  $materials  The reaction's input materials
     * @param  array<int, bool>  $reactionProductTypeIds  Set of type IDs that are products of reactions
     */
    public static function resolveReactionComplexity(array $materials, array $reactionProductTypeIds): string
    {
        foreach ($materials as $material) {
            if (isset($reactionProductTypeIds[$material['type_id']])) {
                return 'complex';
            }
        }

        return 'intermediate';
    }

    /**
     * Build a lookup set of all type IDs that are products of reaction activities.
     *
     * @return array<int, bool> Keyed by type_id for O(1) lookups
     */
    public function getReactionProductTypeIds(?\Illuminate\Support\Collection $activities = null): array
    {
        if ($activities === null) {
            $activities = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_REACTION)
                ->whereNotNull('products')
                ->get();
        }

        $typeIds = [];
        foreach ($activities as $activity) {
            $products = $activity->products ?? [];
            foreach ($products as $product) {
                if (isset($product['type_id'])) {
                    $typeIds[$product['type_id']] = true;
                }
            }
        }

        return $typeIds;
    }
}
