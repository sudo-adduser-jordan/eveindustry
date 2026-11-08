<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\InvCategory;
use App\Models\InvGroup;
use App\Models\ManufacturingCost;
use App\Models\MarketGroup;
use App\Models\MarketHistory;
use Illuminate\Support\Collection;

class ManufacturingBrowserService
{
    /**
     * Largest id list sent in one whereIn(). Keeps bound parameters well under
     * the MySQL and SQLite limits while still being one query for a normal
     * region's worth of blueprints.
     */
    private const WHERE_IN_CHUNK = 5000;

    /** Number of most recent market_history rows the sell score looks at. */
    private const SELL_SCORE_HISTORY_DAYS = 30;

    private ?array $inventedBlueprintTypeIds = null;

    public function __construct(
        protected ManufacturingCalculatorService $calculator,
        protected MarketService $marketService,
        protected EsiService $esi
    ) {}

    /**
     * Get the distinct product categories available from manufacturing blueprints.
     *
     * Groups are resolved via the local inv_groups/inv_categories tables
     * to determine their parent category_id, then categories are aggregated
     * with item counts.
     *
     * @return array<int, array{category_id: int, name: string, item_count: int}>
     */
    public function getAvailableProductCategories(): array
    {
        $inventedBlueprintTypeIds = $this->getInventedBlueprintTypeIds();

        $activities = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
            ->whereNotNull('products')
            ->whereNotIn('blueprint_type_id', $inventedBlueprintTypeIds)
            ->get();

        // Collect all product type IDs
        $productTypeIds = [];
        foreach ($activities as $activity) {
            $products = $activity->products ?? [];
            foreach ($products as $product) {
                if (isset($product['type_id'])) {
                    $productTypeIds[] = $product['type_id'];
                }
            }
        }
        $productTypeIds = array_unique($productTypeIds);

        if (empty($productTypeIds)) {
            return [];
        }

        // Get distinct group_ids from the product types
        $groupIds = EveType::whereIn('type_id', $productTypeIds)
            ->whereNotNull('group_id')
            ->distinct()
            ->pluck('group_id')
            ->toArray();

        // Resolve each group's category_id via local inv_groups table, and count items per group
        $groupItemCounts = EveType::whereIn('type_id', $productTypeIds)
            ->whereNotNull('group_id')
            ->selectRaw('group_id, COUNT(*) as item_count')
            ->groupBy('group_id')
            ->pluck('item_count', 'group_id')
            ->toArray();

        // Pre-load group → category mapping from local table
        $groupCategoryMap = InvGroup::whereIn('group_id', $groupIds)
            ->whereNotNull('category_id')
            ->pluck('category_id', 'group_id')
            ->toArray();

        // Map group_id → category_id and aggregate item counts by category
        $categoryCounts = [];
        foreach ($groupIds as $groupId) {
            $categoryId = $groupCategoryMap[$groupId] ?? null;
            if ($categoryId === null) {
                continue;
            }

            $categoryCounts[$categoryId] = ($categoryCounts[$categoryId] ?? 0)
                + ($groupItemCounts[$groupId] ?? 0);
        }

        // Resolve category names from local inv_categories table
        $categoryNames = InvCategory::whereIn('category_id', array_keys($categoryCounts))
            ->pluck('name', 'category_id')
            ->toArray();

        $categories = [];
        foreach ($categoryCounts as $categoryId => $itemCount) {
            $categoryName = $categoryNames[$categoryId] ?? "Category {$categoryId}";

            $categories[] = [
                'category_id' => $categoryId,
                'name' => $categoryName,
                'item_count' => $itemCount,
            ];
        }

        usort($categories, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $categories;
    }

    /**
     * Fetch T1 manufacturing blueprints from the local blueprint_activities table,
     * optionally filtered by the product's category (resolved via group → category).
     *
     * By default, T2 Modules and T2/T3 Ships are excluded from the results.
     *
     * @param  int|null  $categoryId  Filter by product category_id (null = all categories)
     * @return array<int, array{blueprint_type_id: int, blueprint_name: string, materials: array, products: array, time: int}>
     */
    public function getAllManufacturingBlueprints(?int $categoryId = null): array
    {
        $inventedBlueprintTypeIds = $this->getInventedBlueprintTypeIds();

        $activities = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
            ->whereNotNull('products')
            ->whereNotIn('blueprint_type_id', $inventedBlueprintTypeIds)
            ->get();

        // If filtering by category, collect the product type IDs that belong to this category
        $allowedProductTypeIds = null;
        if ($categoryId !== null) {
            $allowedProductTypeIds = $this->getProductTypeIdsForCategory($activities, $categoryId);
        }

        // Build a set of excluded product type IDs (T2 Modules and T2/T3 Ships)
        $excludedProductTypeIds = $this->getExcludedManufacturingProductTypeIds($activities);

        $items = [];

        foreach ($activities as $activity) {
            $products = $activity->products ?? [];
            if (empty($products)) {
                continue;
            }

            $productTypeId = $products[0]['type_id'] ?? null;

            // Filter by category if specified
            if ($allowedProductTypeIds !== null) {
                if ($productTypeId === null || ! isset($allowedProductTypeIds[$productTypeId])) {
                    continue;
                }
            }

            // Exclude T2 Modules and T2/T3 Ships
            if ($productTypeId !== null && isset($excludedProductTypeIds[$productTypeId])) {
                continue;
            }

            $items[] = [
                'blueprint_type_id' => $activity->blueprint_type_id,
                'blueprint_name' => null,
                'materials' => $activity->materials ?? [],
                'products' => $products,
                'time' => $activity->time ?? 0,
            ];
        }

        return $this->nameAndSortBlueprints($items);
    }

    /**
     * Calculate profitability for T1 manufacturing items given user parameters.
     *
     * @param  int  $runs  Number of runs
     * @param  int  $meLevel  Material Efficiency level (0-10)
     * @param  float  $structureMeBonus  Structure ME bonus percentage
     * @param  float  $facilityTaxRate  Facility tax rate percentage (0-50)
     * @param  float  $systemCostIndex  System cost index percentage (0-100)
     * @param  int  $regionId  Market region for price lookups
     * @param  int|null  $categoryId  Filter by product category_id (null = all categories)
     * @param  int|null  $marketGroupId  Filter by market_group_id (includes descendants)
     * @param  int  $page  Page number for pagination (1-based)
     * @param  int  $perPage  Items per page
     * @return array{items: array, total: int, page: int, per_page: int, last_page: int}
     */
    public function calculateAllProfitability(
        int $runs = 1,
        int $meLevel = 0,
        float $structureMeBonus = 0.0,
        float $facilityTaxRate = 0.0,
        float $systemCostIndex = 0.0,
        int $regionId = MarketService::DEFAULT_REGION_ID,
        ?int $categoryId = null,
        ?int $marketGroupId = null,
        int $page = 1,
        int $perPage = 100
    ): array {
        if ($marketGroupId !== null) {
            $items = $this->getManufacturingBlueprintsForMarketGroup($marketGroupId);
        } else {
            $items = $this->getAllManufacturingBlueprints($categoryId);
        }

        if (empty($items)) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 1];
        }

        // Collect all type IDs needed for price lookups
        $allTypeIds = [];
        foreach ($items as $item) {
            foreach ($item['materials'] as $mat) {
                $allTypeIds[] = $mat['type_id'];
            }
            foreach ($item['products'] as $prod) {
                $allTypeIds[] = $prod['type_id'];
            }
        }
        $allTypeIds = array_values(array_unique($allTypeIds));

        // Use lowest sell prices (orders → history → market_prices fallback)
        $sellPrices = $this->marketService->getSellPrices($allTypeIds, $regionId);

        // Sell scores for every product, read from market history in one go
        $sellScoreRequests = [];
        foreach ($items as $index => $item) {
            $productTypeId = $item['products'][0]['type_id'] ?? null;
            if ($productTypeId) {
                $sellScoreRequests[$index] = [
                    'type_id' => $productTypeId,
                    'total_output' => $runs * ($item['products'][0]['quantity'] ?? 1),
                ];
            }
        }
        $sellScores = $this->calculateSellScores($sellScoreRequests, $regionId);

        $results = [];

        foreach ($items as $index => $item) {
            $products = $item['products'];
            $productTypeId = $products[0]['type_id'] ?? null;
            $productName = $products[0]['name'] ?? 'Unknown';
            $portionSize = $products[0]['quantity'] ?? 1;

            if (! $productTypeId) {
                continue;
            }

            // Calculate adjusted materials with ME
            $materials = $this->calculator->calculateMaterials(
                $item['materials'],
                $meLevel,
                $runs,
                $structureMeBonus
            );

            // Calculate input cost using sell prices
            $totalInputCost = 0;
            foreach ($materials as $material) {
                $unitPrice = $sellPrices[$material['type_id']] ?? 0;
                $totalInputCost += $unitPrice * $material['adjusted_quantity'];
            }

            // Calculate output value
            $totalOutput = $runs * $portionSize;
            $productUnitPrice = $sellPrices[$productTypeId] ?? 0;
            $totalOutputValue = $productUnitPrice * $totalOutput;

            // Job fee calculation
            ['job_fee' => $jobFee, 'facility_tax' => $facilityTax, 'total_job_cost' => $totalJobCost] = IndustryProfitCalculator::jobCost($totalInputCost, $systemCostIndex, $facilityTaxRate);

            // SCC fee, profit and margin on the same basis as the calculator
            $summary = IndustryProfitCalculator::summary($totalOutputValue, $totalInputCost, $totalJobCost);
            $sccFee = $summary['scc_fee'];
            $profit = $summary['profit'];
            $profitMargin = $summary['profit_margin'];
            $profitPerUnit = $totalOutput > 0 ? $profit / $totalOutput : 0;

            $sellScore = $sellScores[$index];

            $results[] = [
                'blueprint_type_id' => $item['blueprint_type_id'],
                'blueprint_name' => $item['blueprint_name'],
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

        // Paginate results
        $total = count($results);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $paginatedItems = array_slice($results, $offset, $perPage);

        return [
            'items' => $paginatedItems,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Apply a user's job settings to one precomputed row (1 run, no structure
     * bonus, no job fees).
     *
     * The structure ME bonus scales the whole input cost, as the browser
     * page always has; the calculator page rounds per material instead.
     * profit_margin is the adjusted margin; base_profit_margin keeps the
     * stored one. With 1 run and no bonus or fees the two are equal.
     *
     * @return array<string, mixed>
     */
    public static function applyUserAdjustments(
        float $baseInputCost,
        float $baseOutputValue,
        int $portionSize,
        int $runs = 1,
        float $structureMeBonus = 0.0,
        float $facilityTaxRate = 0.0,
        float $systemCostIndex = 0.0
    ): array {
        $inputCost = $baseInputCost * $runs * (1.0 - $structureMeBonus / 100.0);
        $totalOutput = $runs * $portionSize;
        $outputValue = $baseOutputValue * $runs;

        $job = IndustryProfitCalculator::jobCost($inputCost, $systemCostIndex, $facilityTaxRate);
        $summary = IndustryProfitCalculator::summary($outputValue, $inputCost, $job['total_job_cost']);
        $profit = $summary['profit'];
        $profitPerUnit = $totalOutput > 0 ? $profit / $totalOutput : 0;

        return [
            'total_output' => $totalOutput,
            'total_input_cost' => $inputCost,
            'total_output_value' => $outputValue,
            'job_fee' => $job['job_fee'],
            'facility_tax' => $job['facility_tax'],
            'total_job_cost' => $job['total_job_cost'],
            'scc_fee' => $summary['scc_fee'],
            'profit' => $profit,
            'profit_positive' => $profit >= 0,
            'profit_per_unit' => $profitPerUnit,
            'profit_margin' => $summary['profit_margin'],
            'total_input_cost_formatted' => MarketService::formatIsk($inputCost),
            'total_output_value_formatted' => MarketService::formatIsk($outputValue),
            'total_job_cost_formatted' => MarketService::formatIsk($job['total_job_cost']),
            'scc_fee_formatted' => MarketService::formatIsk($summary['scc_fee']),
            'profit_formatted' => MarketService::formatIsk(abs($profit)),
            'profit_per_unit_formatted' => MarketService::formatIsk(abs($profitPerUnit)),
        ];
    }

    /**
     * Browse precomputed manufacturing costs for a market group.
     *
     * Returns precomputed base costs (1 run, no structure bonus or job fees)
     * for the selected market group and region/ME level, plus the user's
     * runs, structure ME bonus, job fee, facility tax, SCC fee, profit and
     * margin applied to each item (see applyUserAdjustments()).
     *
     * @param  int  $marketGroupId  Market group to browse
     * @param  int  $regionId  Market region
     * @param  int  $meLevel  Material Efficiency level (0-10)
     * @param  int  $page  Page number
     * @param  int  $perPage  Items per page
     * @param  int  $runs  Runs per job
     * @param  float  $structureMeBonus  Structure ME bonus percentage
     * @param  float  $facilityTaxRate  Facility tax percentage
     * @param  float  $systemCostIndex  System cost index percentage
     * @return array{items: array, total: int, page: int, per_page: int, last_page: int}
     */
    public function browsePrecomputed(
        int $marketGroupId,
        int $regionId = MarketService::DEFAULT_REGION_ID,
        int $meLevel = 0,
        int $page = 1,
        int $perPage = 100,
        int $runs = 1,
        float $structureMeBonus = 0.0,
        float $facilityTaxRate = 0.0,
        float $systemCostIndex = 0.0
    ): array {
        $typeIds = $this->collectMarketGroupTypeIds($marketGroupId);

        if (empty($typeIds)) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'last_page' => 1];
        }

        $query = ManufacturingCost::whereIn('product_type_id', $typeIds)
            ->where('region_id', $regionId)
            ->where('me_level', $meLevel)
            ->orderByDesc('base_profit');

        $total = $query->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;

        $rows = $query->skip($offset)->take($perPage)->get();

        $items = $rows->map(function (ManufacturingCost $row) use ($runs, $structureMeBonus, $facilityTaxRate, $systemCostIndex) {
            $base = [
                'blueprint_type_id' => $row->blueprint_type_id,
                'blueprint_name' => $row->blueprint_name,
                'product_type_id' => $row->product_type_id,
                'product_name' => $row->product_name,
                'product_icon_url' => EsiService::getTypeImageUrl($row->product_type_id, 'icon', 32),
                'portion_size' => $row->portion_size,
                'input_cost' => $row->input_cost,
                'output_value' => $row->output_value,
                'base_profit' => $row->base_profit,
                'base_profit_margin' => $row->profit_margin,
                'input_cost_formatted' => MarketService::formatIsk($row->input_cost),
                'output_value_formatted' => MarketService::formatIsk($row->output_value),
                'base_profit_formatted' => MarketService::formatIsk(abs($row->base_profit)),
                'base_profit_positive' => $row->base_profit >= 0,
                'sell_score' => $row->sell_score,
                'sell_score_avg_daily_volume' => $row->sell_score_avg_daily_volume,
                'sell_score_avg_order_count' => $row->sell_score_avg_order_count,
                'sell_score_active_days' => $row->sell_score_active_days,
                'sell_score_total_days' => $row->sell_score_total_days,
            ];

            return array_merge($base, self::applyUserAdjustments(
                (float) $row->input_cost,
                (float) $row->output_value,
                (int) $row->portion_size,
                $runs,
                $structureMeBonus,
                $facilityTaxRate,
                $systemCostIndex
            ));
        })->toArray();

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
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
            ->take(self::SELL_SCORE_HISTORY_DAYS)
            ->get();

        return $this->sellScoreFromHistory($history, $totalOutput);
    }

    /**
     * Sell scores for many products in one region, with the same result as
     * calling calculateSellScore() for each, but reading market history with
     * one query (per 5000 types) instead of one per product. The result is
     * keyed like $products, so one product type can appear more than once
     * with different outputs.
     *
     * @param  array<array-key, array{type_id: int, total_output: int}>  $products
     * @return array<array-key, array{score: int, avg_daily_volume: float, avg_order_count: float, active_days: int, total_days: int}>
     */
    public function calculateSellScores(array $products, int $regionId = MarketService::DEFAULT_REGION_ID): array
    {
        $historyByType = $this->recentHistoryByType(
            array_values(array_unique(array_map(fn ($p) => (int) $p['type_id'], $products))),
            $regionId
        );

        $empty = new Collection;
        $scores = [];
        foreach ($products as $key => $product) {
            $scores[$key] = $this->sellScoreFromHistory(
                $historyByType[(int) $product['type_id']] ?? $empty,
                (int) $product['total_output']
            );
        }

        return $scores;
    }

    /**
     * The latest SELL_SCORE_HISTORY_DAYS market_history rows for each type,
     * newest first. ROW_NUMBER() ranks each type's rows by date so one query
     * covers every type (supported by MySQL 8 and SQLite 3.25+).
     *
     * @param  array<int>  $typeIds
     * @return array<int, Collection<int, MarketHistory>>
     */
    private function recentHistoryByType(array $typeIds, int $regionId): array
    {
        $byType = [];

        foreach (array_chunk($typeIds, self::WHERE_IN_CHUNK) as $chunk) {
            $ranked = MarketHistory::query()
                ->select('market_history.*')
                ->selectRaw('ROW_NUMBER() OVER (PARTITION BY type_id ORDER BY date DESC) AS history_rank')
                ->where('region_id', $regionId)
                ->whereIn('type_id', $chunk);

            $rows = MarketHistory::query()
                ->fromSub($ranked, 'market_history')
                ->where('history_rank', '<=', self::SELL_SCORE_HISTORY_DAYS)
                ->orderBy('type_id')
                ->orderBy('history_rank')
                ->get();

            foreach ($rows->groupBy('type_id') as $typeId => $history) {
                $byType[(int) $typeId] = $history->values();
            }
        }

        return $byType;
    }

    /**
     * Score one product from its recent market history (newest first).
     *
     * @param  Collection<int, MarketHistory>  $history
     * @return array{score: int, avg_daily_volume: float, avg_order_count: float, active_days: int, total_days: int}
     */
    private function sellScoreFromHistory(Collection $history, int $totalOutput): array
    {
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
     * Build a lookup set of product type IDs that belong to a specific category.
     *
     * Resolves group → category mapping via the local inv_groups table to find
     * all groups in the target category, then returns the product type IDs that
     * belong to those groups.
     *
     * @return array<int, bool> Keyed by type_id for O(1) lookups
     */
    private function getProductTypeIdsForCategory(\Illuminate\Support\Collection $activities, int $categoryId): array
    {
        $productTypeIds = [];
        foreach ($activities as $activity) {
            $products = $activity->products ?? [];
            foreach ($products as $product) {
                if (isset($product['type_id'])) {
                    $productTypeIds[] = $product['type_id'];
                }
            }
        }

        // Get distinct group_ids from products
        $groupIds = EveType::whereIn('type_id', array_unique($productTypeIds))
            ->whereNotNull('group_id')
            ->distinct()
            ->pluck('group_id')
            ->toArray();

        // Find which group_ids belong to the target category via local table
        $matchingGroupIds = InvGroup::whereIn('group_id', $groupIds)
            ->where('category_id', $categoryId)
            ->pluck('group_id')
            ->toArray();

        if (empty($matchingGroupIds)) {
            return [];
        }

        // Get type IDs that belong to matching groups
        $matchingTypeIds = EveType::whereIn('type_id', array_unique($productTypeIds))
            ->whereIn('group_id', $matchingGroupIds)
            ->pluck('type_id')
            ->toArray();

        $lookup = [];
        foreach ($matchingTypeIds as $typeId) {
            $lookup[$typeId] = true;
        }

        return $lookup;
    }

    /**
     * Fetch T1 manufacturing blueprints filtered by market group.
     *
     * Collects all type IDs that belong to the given market group and its
     * descendants, then returns only blueprints whose products match.
     * T2 Modules and T2/T3 Ships are excluded.
     *
     * @return array<int, array{blueprint_type_id: int, blueprint_name: string, materials: array, products: array, time: int}>
     */
    public function getManufacturingBlueprintsForMarketGroup(int $marketGroupId): array
    {
        $allowedTypeIds = $this->collectMarketGroupTypeIds($marketGroupId);

        if (empty($allowedTypeIds)) {
            return [];
        }

        $inventedBlueprintTypeIds = $this->getInventedBlueprintTypeIds();

        $activities = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
            ->whereNotNull('products')
            ->whereNotIn('blueprint_type_id', $inventedBlueprintTypeIds)
            ->get();

        // Build a set of excluded product type IDs (T2 Modules and T2/T3 Ships)
        $excludedProductTypeIds = $this->getExcludedManufacturingProductTypeIds($activities);

        $allowedLookup = array_flip($allowedTypeIds);
        $items = [];

        foreach ($activities as $activity) {
            $products = $activity->products ?? [];
            if (empty($products)) {
                continue;
            }

            $productTypeId = $products[0]['type_id'] ?? null;
            if ($productTypeId === null || ! isset($allowedLookup[$productTypeId])) {
                continue;
            }

            // Exclude T2 Modules and T2/T3 Ships
            if (isset($excludedProductTypeIds[$productTypeId])) {
                continue;
            }

            $items[] = [
                'blueprint_type_id' => $activity->blueprint_type_id,
                'blueprint_name' => null,
                'materials' => $activity->materials ?? [],
                'products' => $products,
                'time' => $activity->time ?? 0,
            ];
        }

        return $this->nameAndSortBlueprints($items);
    }

    /**
     * Fill in blueprint names with one query for the whole list (rather than
     * one lookup per blueprint), then sort by name.
     *
     * @param  array<int, array{blueprint_type_id: int, blueprint_name: ?string, materials: array, products: array, time: int}>  $items
     * @return array<int, array{blueprint_type_id: int, blueprint_name: string, materials: array, products: array, time: int}>
     */
    private function nameAndSortBlueprints(array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $names = [];
        $blueprintTypeIds = array_values(array_unique(array_column($items, 'blueprint_type_id')));
        foreach (array_chunk($blueprintTypeIds, self::WHERE_IN_CHUNK) as $chunk) {
            $names += EveType::whereIn('type_id', $chunk)->pluck('name', 'type_id')->all();
        }

        foreach ($items as &$item) {
            $item['blueprint_name'] = $names[$item['blueprint_type_id']] ?? 'Unknown Blueprint';
        }
        unset($item);

        usort($items, fn ($a, $b) => strcmp($a['blueprint_name'], $b['blueprint_name']));

        return $items;
    }

    /**
     * Collect all type_ids belonging to a market group and all its descendants.
     *
     * Recursively traverses the market group tree and collects published
     * EveType type_ids from all leaf groups.
     *
     * @return array<int> List of type_ids
     */
    public function collectMarketGroupTypeIds(int $marketGroupId): array
    {
        $groupIds = $this->collectDescendantGroupIds($marketGroupId);
        $groupIds[] = $marketGroupId;

        return EveType::whereIn('market_group_id', $groupIds)
            ->where('published', true)
            ->pluck('type_id')
            ->toArray();
    }

    /**
     * Collect all descendant market group IDs.
     *
     * Walks the tree one level at a time, so it runs one query per level of
     * depth rather than one per group.
     *
     * @return array<int> List of descendant market_group_ids
     */
    private function collectDescendantGroupIds(int $parentGroupId): array
    {
        $seen = [$parentGroupId => true];
        $allIds = [];
        $level = [$parentGroupId];

        while (! empty($level)) {
            $childIds = MarketGroup::whereIn('parent_group_id', $level)
                ->pluck('market_group_id')
                ->toArray();

            $level = [];
            foreach ($childIds as $childId) {
                if (isset($seen[$childId])) {
                    continue;
                }
                $seen[$childId] = true;
                $allIds[] = $childId;
                $level[] = $childId;
            }
        }

        return $allIds;
    }

    /**
     * Build a lookup set of product type IDs that should be excluded from the
     * manufacturing browser: T2 Modules and T2/T3 Ships.
     *
     * Uses the meta_group_id on EveType and the category_id from inv_groups
     * to determine which products to exclude.
     *
     * @return array<int, true> Keyed by type_id for O(1) lookups
     */
    private function getExcludedManufacturingProductTypeIds(\Illuminate\Support\Collection $activities): array
    {
        // EVE Online category IDs
        $shipCategoryId = 6;
        $moduleCategoryId = 7;

        // Collect all product type IDs
        $productTypeIds = [];
        foreach ($activities as $activity) {
            $products = $activity->products ?? [];
            foreach ($products as $product) {
                if (isset($product['type_id'])) {
                    $productTypeIds[] = $product['type_id'];
                }
            }
        }
        $productTypeIds = array_unique($productTypeIds);

        if (empty($productTypeIds)) {
            return [];
        }

        // Pre-load group → category mapping
        $groupCategoryMap = InvGroup::pluck('category_id', 'group_id')->toArray();

        // Find product types that are T2 Modules or T2/T3 Ships
        $excludedTypes = EveType::whereIn('type_id', $productTypeIds)
            ->whereNotNull('group_id')
            ->whereNotNull('meta_group_id')
            ->where(function ($query) {
                $query->where('meta_group_id', EveType::META_GROUP_TECH_II)
                    ->orWhere('meta_group_id', EveType::META_GROUP_TECH_III);
            })
            ->get(['type_id', 'group_id', 'meta_group_id']);

        $excluded = [];
        foreach ($excludedTypes as $type) {
            $categoryId = $groupCategoryMap[$type->group_id] ?? null;

            // Exclude T2 Modules
            if ($type->meta_group_id === EveType::META_GROUP_TECH_II && $categoryId === $moduleCategoryId) {
                $excluded[$type->type_id] = true;
            }

            // Exclude T2/T3 Ships
            if ($categoryId === $shipCategoryId && in_array($type->meta_group_id, [EveType::META_GROUP_TECH_II, EveType::META_GROUP_TECH_III], true)) {
                $excluded[$type->type_id] = true;
            }
        }

        return $excluded;
    }

    /**
     * Get blueprint type IDs that are products of invention activities.
     *
     * These are T2/T3 blueprints that can only be obtained as copies (BPCs)
     * through invention, not purchased as originals (BPOs).
     *
     * Results are memoized for the lifetime of this service instance.
     *
     * @return array<int> Blueprint type IDs produced by invention
     */
    private function getInventedBlueprintTypeIds(): array
    {
        if ($this->inventedBlueprintTypeIds !== null) {
            return $this->inventedBlueprintTypeIds;
        }

        return $this->inventedBlueprintTypeIds = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
            ->whereNotNull('products')
            ->get()
            ->flatMap(fn ($activity) => collect($activity->products)->pluck('type_id'))
            ->unique()
            ->values()
            ->toArray();
    }
}
