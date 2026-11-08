<?php

namespace App\Jobs;

use App\Models\EveType;
use App\Models\ManufacturingCost;
use App\Services\IndustryProfitCalculator;
use App\Services\ManufacturingBrowserService;
use App\Services\ManufacturingCalculatorService;
use App\Services\MarketAggregateService;
use App\Services\MarketService;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PrecomputeManufacturingCostsChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /** @var DateTimeInterface Fixed queue retry horizon so lock contention is bounded. */
    public DateTimeInterface $retryUntilAt;

    private const REGION_LOCK_SECONDS = 900;

    private const CONTENTION_RETRY_SECONDS = 30;

    /**
     * @param  int  $regionId  The region to compute costs for
     * @param  array<int>  $allowedMetaGroups  Allowed meta group IDs (ignored when $includeAll is true)
     * @param  bool  $includeAll  Whether to include all meta group types
     */
    public function __construct(
        protected int $regionId,
        protected array $allowedMetaGroups,
        protected bool $includeAll,
    ) {
        // A region takes minutes, so it runs with the long imports rather than
        // holding up short per-user refreshes on the default queue.
        $this->onQueue('updates');
        $this->retryUntilAt = now()->addMinutes(30);
    }

    public function retryUntil(): DateTimeInterface
    {
        return $this->retryUntilAt;
    }

    public function handle(
        ManufacturingBrowserService $browser,
        ManufacturingCalculatorService $calculator,
        MarketService $marketService,
        ?MarketAggregateService $aggregates = null,
    ): void {
        // Cache's Redis lock connection is configured to use the non-evicting
        // queue Redis connection. A lock-store failure must fail this job; it
        // must never fall through to an uncoordinated write.
        $lock = Cache::store()->lock("manufacturing-costs:region:{$this->regionId}", self::REGION_LOCK_SECONDS);

        if (! $lock->get()) {
            $this->release(self::CONTENTION_RETRY_SECONDS);

            return;
        }

        try {
            $this->processRegion($browser, $calculator, $marketService, $aggregates);
        } finally {
            $lock->release();
        }
    }

    private function processRegion(
        ManufacturingBrowserService $browser,
        ManufacturingCalculatorService $calculator,
        MarketService $marketService,
        ?MarketAggregateService $aggregates,
    ): void {
        $aggregates ??= app(MarketAggregateService::class);
        $runStartedAt = now();

        Log::info("PrecomputeManufacturingCostsChunk: region {$this->regionId}, ME levels 0-10");

        // Fetch and filter blueprints inside the job so the queue payload stays
        // small (only scalars) instead of serializing the full item list per region.
        $items = $browser->getAllManufacturingBlueprints();
        if (! $this->includeAll) {
            $items = $this->filterByMetaGroup($items, $this->allowedMetaGroups);
        }

        // Keep rows for a duplicate product in a stable blueprint order so
        // the product/region/ME unique key receives a deterministic winner.
        usort($items, fn (array $left, array $right) => ($left['blueprint_type_id'] ?? 0) <=> ($right['blueprint_type_id'] ?? 0));

        if (empty($items)) {
            Log::info("PrecomputeManufacturingCostsChunk: region {$this->regionId} has no blueprints to process");

            return;
        }

        // Collect all type IDs needed for price lookups.
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

        // The snapshot the prices below come from (null until the region has
        // a completed order sweep); read first so it never post-dates them.
        $snapshot = $aggregates->currentSnapshot($this->regionId);
        $pricesAsOf = $snapshot !== null ? Carbon::parse($snapshot['snapshot_at'])->utc()->toDateTimeString() : null;

        // Fetch prices once for the entire region
        $sellPrices = $marketService->getSellPrices($allTypeIds, $this->regionId);

        // Sell scores don't depend on ME level: one batch for every item,
        // read from market history in one go rather than one query per item.
        $sellScoreRequests = [];
        foreach ($items as $index => $item) {
            $productTypeId = $item['products'][0]['type_id'] ?? null;
            if ($productTypeId) {
                $sellScoreRequests[$index] = [
                    'type_id' => $productTypeId,
                    'total_output' => $item['products'][0]['quantity'] ?? 1,
                ];
            }
        }
        $sellScores = empty($sellScoreRequests)
            ? []
            : $browser->calculateSellScores($sellScoreRequests, $this->regionId);

        $meLevels = range(0, 10);
        $batch = [];
        $written = 0;
        $now = now();

        foreach ($items as $index => $item) {
            $products = $item['products'];
            $productTypeId = $products[0]['type_id'] ?? null;
            $productName = $products[0]['name'] ?? 'Unknown';
            $portionSize = $products[0]['quantity'] ?? 1;

            if (! $productTypeId) {
                continue;
            }

            // Output value doesn't depend on ME level — compute once per item
            $totalOutput = $portionSize;
            $productUnitPrice = $sellPrices[$productTypeId] ?? 0;
            $totalOutputValue = $productUnitPrice * $totalOutput;

            $sellScore = $sellScores[$index];

            // Loop through all ME levels for material cost calculations
            foreach ($meLevels as $meLevel) {
                $materials = $calculator->calculateMaterials(
                    $item['materials'],
                    $meLevel,
                    1,
                    0.0
                );

                $totalInputCost = 0;
                foreach ($materials as $material) {
                    $unitPrice = $sellPrices[$material['type_id']] ?? 0;
                    $totalInputCost += $unitPrice * $material['adjusted_quantity'];
                }

                // Base profit after the SCC fee, on the same basis as the calculator.
                // Job fees depend on the user's structure, so the browser adds those.
                $summary = IndustryProfitCalculator::summary($totalOutputValue, $totalInputCost);
                $baseProfit = $summary['profit'];
                $profitMargin = $summary['profit_margin'];

                $batch[] = [
                    'product_type_id' => $productTypeId,
                    'blueprint_type_id' => $item['blueprint_type_id'],
                    'region_id' => $this->regionId,
                    'me_level' => $meLevel,
                    'product_name' => $productName,
                    'blueprint_name' => $item['blueprint_name'],
                    'portion_size' => $portionSize,
                    'input_cost' => $totalInputCost,
                    'output_value' => $totalOutputValue,
                    'base_profit' => $baseProfit,
                    'profit_margin' => $profitMargin,
                    'sell_score' => $sellScore['score'],
                    'sell_score_avg_daily_volume' => $sellScore['avg_daily_volume'],
                    'sell_score_avg_order_count' => $sellScore['avg_order_count'],
                    'sell_score_active_days' => $sellScore['active_days'],
                    'sell_score_total_days' => $sellScore['total_days'],
                    'prices_as_of' => $pricesAsOf,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Flush batch periodically to limit memory
                // Twenty columns per row stays below SQLite's conservative
                // bind limit in tests and bounds SQL/exception payload size.
                if (count($batch) >= 40) {
                    $written += $this->flush($batch);
                    $batch = [];
                }
            }
        }

        // Flush remaining records
        $written += $this->flush($batch);

        // Rows this run did not rewrite are products that dropped out of the
        // blueprint set or the meta-group filter; remove them so the browser
        // never shows a cost computed from long-gone prices.
        if ($written > 0) {
            $removed = ManufacturingCost::where('region_id', $this->regionId)
                ->where('updated_at', '<', $runStartedAt)
                ->delete();

            if ($removed > 0) {
                Log::info("PrecomputeManufacturingCostsChunk: region {$this->regionId} removed {$removed} stale rows");
            }
        }

        Log::info("PrecomputeManufacturingCostsChunk: region {$this->regionId} completed all ME levels");
    }

    /**
     * Upsert one batch of rows.
     *
     * @return int rows written
     */
    private function flush(array $batch): int
    {
        if ($batch === []) {
            return 0;
        }

        usort($batch, fn (array $left, array $right) => [
            $left['product_type_id'], $left['region_id'], $left['me_level'], $left['blueprint_type_id'],
        ] <=> [
            $right['product_type_id'], $right['region_id'], $right['me_level'], $right['blueprint_type_id'],
        ]);

        app(ManufacturingCostWriter::class)->upsert($this->regionId, $batch);

        return count($batch);
    }

    /**
     * Filter blueprint items to only include those whose products have an
     * allowed meta_group_id (or null, which is treated as T1).
     *
     * @param  array  $items  Blueprint items
     * @param  array<int>  $allowedMetaGroups  Allowed meta group IDs
     * @return array Filtered items
     */
    private function filterByMetaGroup(array $items, array $allowedMetaGroups): array
    {
        // Collect all product type IDs
        $productTypeIds = [];
        foreach ($items as $item) {
            $products = $item['products'] ?? [];
            if (! empty($products) && isset($products[0]['type_id'])) {
                $productTypeIds[] = $products[0]['type_id'];
            }
        }

        if (empty($productTypeIds)) {
            return $items;
        }

        // Look up meta_group_id for all products
        $metaGroups = EveType::whereIn('type_id', array_unique($productTypeIds))
            ->pluck('meta_group_id', 'type_id')
            ->toArray();

        $includesT1 = in_array(EveType::META_GROUP_TECH_I, $allowedMetaGroups, true);

        return array_values(array_filter($items, function ($item) use ($metaGroups, $allowedMetaGroups, $includesT1) {
            $productTypeId = $item['products'][0]['type_id'] ?? null;
            if ($productTypeId === null) {
                return false;
            }

            $metaGroupId = $metaGroups[$productTypeId] ?? null;

            // Items with null meta_group_id are treated as T1
            if ($metaGroupId === null) {
                return $includesT1;
            }

            return in_array($metaGroupId, $allowedMetaGroups, true);
        }));
    }
}
