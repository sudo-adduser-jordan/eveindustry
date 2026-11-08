<?php

namespace Tests\Unit;

use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\ManufacturingCost;
use App\Models\MarketGroup;
use App\Models\MarketHistory;
use App\Models\MarketOrder;
use App\Services\ManufacturingBrowserService;
use App\Services\ManufacturingCalculatorService;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Browser reads stay batched as blueprint count grows. Precompute writes are
 * bounded to 40 rows per statement, so write count grows by known chunks.
 */
class ManufacturingBrowserServiceQueryCountTest extends TestCase
{
    use RefreshDatabase;

    private const REGION_ID = MarketService::DEFAULT_REGION_ID;

    private const LEAF_MARKET_GROUP_ID = 3;

    private int $blueprintCount = 0;

    private int $orderId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        // Root (1) → child (2) → leaf (3), products sit in the leaf
        MarketGroup::create(['market_group_id' => 1, 'name' => 'Root', 'parent_group_id' => null]);
        MarketGroup::create(['market_group_id' => 2, 'name' => 'Child', 'parent_group_id' => 1]);
        MarketGroup::create(['market_group_id' => self::LEAF_MARKET_GROUP_ID, 'name' => 'Leaf', 'parent_group_id' => 2]);

        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'published' => true]);
        $this->createSellOrder(34, 5.0);
    }

    public function test_calculate_all_profitability_query_count_does_not_grow_with_items(): void
    {
        $this->assertQueryCountFlat(fn () => $this->freshService()->calculateAllProfitability());
    }

    public function test_calculate_all_profitability_for_market_group_query_count_does_not_grow_with_items(): void
    {
        $this->assertQueryCountFlat(fn () => $this->freshService()->calculateAllProfitability(marketGroupId: 1));
    }

    public function test_get_all_manufacturing_blueprints_query_count_does_not_grow_with_items(): void
    {
        $this->assertQueryCountFlat(fn () => $this->freshService()->getAllManufacturingBlueprints());
    }

    public function test_precompute_job_query_count_does_not_grow_with_items(): void
    {
        $this->addBlueprints(2);
        $withTwo = $this->countQueryBreakdown(function () {
            (new PrecomputeManufacturingCostsChunk(self::REGION_ID, [], true))->handle(
                $this->freshService(),
                app(ManufacturingCalculatorService::class),
                app(MarketService::class),
            );
        });

        $this->addBlueprints(18);
        $withTwenty = $this->countQueryBreakdown(function () {
            (new PrecomputeManufacturingCostsChunk(self::REGION_ID, [], true))->handle(
                $this->freshService(),
                app(ManufacturingCalculatorService::class),
                app(MarketService::class),
            );
        });

        $this->assertSame($withTwo['reads'], $withTwenty['reads'], 'Precompute reads must remain batched as blueprint count grows.');
        $this->assertSame($withTwo['other'], $withTwenty['other'], 'Non-upsert writes must remain constant.');
        $this->assertSame(1, $withTwo['upserts'], '22 cost rows fit in one 40-row upsert chunk.');
        $this->assertSame(6, $withTwenty['upserts'], '220 cost rows require six 40-row upsert chunks.');

        // The job really wrote every item at every ME level
        $this->assertSame(20 * 11, ManufacturingCost::count());
    }

    public function test_browse_precomputed_query_count_does_not_grow_with_sibling_market_groups(): void
    {
        $this->addSiblingGroups(2);
        $before = $this->countQueries(fn () => $this->freshService()->browsePrecomputed(1));

        $this->addSiblingGroups(18);
        $after = $this->countQueries(fn () => $this->freshService()->browsePrecomputed(1));

        $this->assertSame($before, $after);
    }

    public function test_calculate_sell_scores_uses_one_query(): void
    {
        $this->addBlueprints(20);
        $products = [];
        for ($i = 0; $i < 20; $i++) {
            $products[] = ['type_id' => 200000 + $i, 'total_output' => 1];
        }

        $service = $this->freshService();
        $this->assertSame(1, $this->countQueries(fn () => $service->calculateSellScores($products, self::REGION_ID)));
    }

    /**
     * Run $action with 2 blueprints and again with 20, and check both runs
     * use the same number of queries.
     */
    private function assertQueryCountFlat(callable $action): void
    {
        $this->addBlueprints(2);
        $withTwo = $this->countQueries($action);

        $this->addBlueprints(18);
        $withTwenty = $this->countQueries($action);

        $this->assertGreaterThan(0, $withTwo);
        $this->assertSame($withTwo, $withTwenty, "Queries with 2 items: {$withTwo}, with 20 items: {$withTwenty}");
    }

    private function countQueries(callable $action): int
    {
        // Every run starts cold: otherwise the first run caches the region's
        // (absent) aggregate snapshot and saves the next run one query.
        \Illuminate\Support\Facades\Cache::forget(\App\Services\MarketAggregateService::SNAPSHOT_CACHE_PREFIX.self::REGION_ID);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        return $count;
    }

    /** @return array{reads: int, upserts: int, other: int} */
    private function countQueryBreakdown(callable $action): array
    {
        \Illuminate\Support\Facades\Cache::forget(\App\Services\MarketAggregateService::SNAPSHOT_CACHE_PREFIX.self::REGION_ID);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        $reads = 0;
        $upserts = 0;
        foreach ($queries as $query) {
            $sql = ltrim($query['query']);
            if (preg_match('/^select\b/i', $sql)) {
                $reads++;
            } elseif (preg_match('/^insert\s+into\s+["`]?manufacturing_costs["`]?/i', $sql)) {
                $upserts++;
            }
        }

        return ['reads' => $reads, 'upserts' => $upserts, 'other' => count($queries) - $reads - $upserts];
    }

    private function freshService(): ManufacturingBrowserService
    {
        // A new instance, so memoised lookups don't hide queries
        return app()->make(ManufacturingBrowserService::class);
    }

    /**
     * Blueprints whose products have sell orders, market history and
     * precomputed rows.
     */
    private function addBlueprints(int $count): void
    {
        for ($n = 0; $n < $count; $n++) {
            $i = $this->blueprintCount++;
            $blueprintTypeId = 100000 + $i;
            $productTypeId = 200000 + $i;

            EveType::create(['type_id' => $blueprintTypeId, 'name' => "Blueprint {$i}", 'published' => true]);
            EveType::create([
                'type_id' => $productTypeId,
                'name' => "Product {$i}",
                'published' => true,
                'market_group_id' => self::LEAF_MARKET_GROUP_ID,
            ]);
            BlueprintActivity::create([
                'blueprint_type_id' => $blueprintTypeId,
                'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
                'time' => 600,
                'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
                'products' => [['type_id' => $productTypeId, 'name' => "Product {$i}", 'quantity' => 1]],
            ]);
            $this->createSellOrder($productTypeId, 1000.0 + $i);

            for ($d = 0; $d < 5; $d++) {
                MarketHistory::create([
                    'type_id' => $productTypeId,
                    'region_id' => self::REGION_ID,
                    'date' => now()->subDays($d)->toDateString(),
                    'average' => 1000,
                    'highest' => 1100,
                    'lowest' => 900,
                    'volume' => 10 + $d,
                    'order_count' => 3,
                ]);
            }
        }
    }

    /**
     * Extra market groups under the root, each with a child, so the tree
     * gets wider but no deeper.
     */
    private function addSiblingGroups(int $count): void
    {
        static $nextId = 1000;

        for ($n = 0; $n < $count; $n++) {
            $groupId = $nextId++;
            MarketGroup::create(['market_group_id' => $groupId, 'name' => "Sibling {$groupId}", 'parent_group_id' => 1]);
            $childId = $nextId++;
            MarketGroup::create(['market_group_id' => $childId, 'name' => "Sibling child {$childId}", 'parent_group_id' => $groupId]);
        }
    }

    private function createSellOrder(int $typeId, float $price): void
    {
        MarketOrder::create([
            'order_id' => $this->orderId++,
            'region_id' => self::REGION_ID,
            'type_id' => $typeId,
            'is_buy_order' => false,
            'price' => $price,
            'volume_remain' => 100,
            'volume_total' => 100,
            'issued' => now()->toDateTimeString(),
        ]);
    }
}
