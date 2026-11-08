<?php

namespace Tests\Unit;

use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\EveType;
use App\Models\ManufacturingCost;
use App\Services\EsiService;
use App\Services\ManufacturingBrowserService;
use App\Services\ManufacturingCalculatorService;
use App\Services\MarketAggregateService;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PrecomputeManufacturingCostsJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    /**
     * A calculateSellScores() stand-in that gives every requested product
     * the same score, keyed like the request.
     */
    private function sellScoresReturning(array $score): \Closure
    {
        return fn (array $products) => array_map(fn () => $score, $products);
    }

    public function test_job_is_queueable(): void
    {
        $job = new PrecomputeManufacturingCostsChunk(10000002, [], true);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
    }

    public function test_job_defers_when_another_writer_holds_the_region_lock(): void
    {
        $regionId = 10000002;
        $lock = Cache::store()->lock("manufacturing-costs:region:{$regionId}", 900);
        $this->assertTrue($lock->get());

        try {
            $browser = $this->mock(ManufacturingBrowserService::class);
            $browser->shouldNotReceive('getAllManufacturingBlueprints');

            $job = new PrecomputeManufacturingCostsChunk($regionId, [], true);
            $fixedRetryDeadline = $job->retryUntil()->getTimestamp();

            $job->handle(
                $browser,
                $this->mock(ManufacturingCalculatorService::class),
                $this->mock(MarketService::class),
            );

            $this->assertSame($fixedRetryDeadline, $job->retryUntil()->getTimestamp());
        } finally {
            $lock->release();
        }

        $this->assertSame(3, $job->tries);
    }

    public function test_job_upserts_manufacturing_costs_for_all_me_levels(): void
    {
        $items = [
            [
                'blueprint_type_id' => 687,
                'blueprint_name' => 'Rifter Blueprint',
                'materials' => [
                    ['type_id' => 34, 'quantity' => 2000, 'name' => 'Tritanium'],
                ],
                'products' => [
                    ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
                ],
            ],
        ];
        $allTypeIds = [34, 587];
        $regionId = 10000002;

        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->once()->andReturn($items);
        // Sell score requested once per item (not per ME level), in one batch
        $mockBrowser->shouldReceive('calculateSellScores')
            ->once()
            ->with([0 => ['type_id' => 587, 'total_output' => 1]], $regionId)
            ->andReturnUsing($this->sellScoresReturning([
                'score' => 7,
                'avg_daily_volume' => 50.0,
                'avg_order_count' => 10.0,
                'active_days' => 28,
                'total_days' => 30,
            ]));

        $mockCalculator = $this->mock(ManufacturingCalculatorService::class);
        // Called once per ME level (11 times)
        $mockCalculator->shouldReceive('calculateMaterials')
            ->times(11)
            ->andReturn([
                ['type_id' => 34, 'adjusted_quantity' => 1900],
            ]);

        $mockMarket = $this->mock(MarketService::class);
        // Prices fetched once per region
        $mockMarket->shouldReceive('getSellPrices')
            ->once()
            ->with($allTypeIds, $regionId)
            ->andReturn([34 => 5.0, 587 => 500000.0]);

        $job = new PrecomputeManufacturingCostsChunk($regionId, [], true);
        $job->handle($mockBrowser, $mockCalculator, $mockMarket);

        // Should have 11 records (ME levels 0-10)
        $this->assertEquals(11, ManufacturingCost::count());

        // Verify ME level 5 specifically
        $cost = ManufacturingCost::where('product_type_id', 587)
            ->where('region_id', $regionId)
            ->where('me_level', 5)
            ->first();

        $this->assertNotNull($cost);
        $this->assertEquals(1900 * 5.0, $cost->input_cost);
        $this->assertEquals(500000.0, $cost->output_value);
        $this->assertEquals(7, $cost->sell_score);

        // Verify all ME levels exist
        for ($me = 0; $me <= 10; $me++) {
            $this->assertDatabaseHas('manufacturing_costs', [
                'product_type_id' => 587,
                'region_id' => $regionId,
                'me_level' => $me,
            ]);
        }
    }

    public function test_job_skips_items_without_products(): void
    {
        $items = [
            [
                'blueprint_type_id' => 999,
                'blueprint_name' => 'Empty Blueprint',
                'materials' => [['type_id' => 34, 'quantity' => 100]],
                'products' => [['type_id' => null, 'name' => 'Unknown', 'quantity' => 1]],
            ],
        ];

        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->once()->andReturn($items);
        $mockCalculator = $this->mock(ManufacturingCalculatorService::class);
        $mockMarket = $this->mock(MarketService::class);
        $mockMarket->shouldReceive('getSellPrices')->andReturn([]);

        $job = new PrecomputeManufacturingCostsChunk(10000002, [], true);
        $job->handle($mockBrowser, $mockCalculator, $mockMarket);

        $this->assertEquals(0, ManufacturingCost::count());
    }

    public function test_job_handles_empty_items(): void
    {
        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->once()->andReturn([]);
        $mockCalculator = $this->mock(ManufacturingCalculatorService::class);
        $mockMarket = $this->mock(MarketService::class);
        $mockMarket->shouldReceive('getSellPrices')->andReturn([]);

        $job = new PrecomputeManufacturingCostsChunk(10000002, [], true);
        $job->handle($mockBrowser, $mockCalculator, $mockMarket);

        $this->assertEquals(0, ManufacturingCost::count());
    }

    public function test_job_fetches_prices_once_per_region(): void
    {
        $items = [
            [
                'blueprint_type_id' => 687,
                'blueprint_name' => 'Rifter Blueprint',
                'materials' => [['type_id' => 34, 'quantity' => 100]],
                'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
            ],
            [
                'blueprint_type_id' => 688,
                'blueprint_name' => 'Slasher Blueprint',
                'materials' => [['type_id' => 34, 'quantity' => 200]],
                'products' => [['type_id' => 585, 'name' => 'Slasher', 'quantity' => 1]],
            ],
        ];
        $allTypeIds = [34, 587, 585];

        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->once()->andReturn($items);
        $mockBrowser->shouldReceive('calculateSellScores')
            ->once()  // One batch holding each item once, not one entry per ME level
            ->with(\Mockery::on(fn ($products) => count($products) === 2
                && array_column($products, 'type_id') === [587, 585]), 10000002)
            ->andReturnUsing($this->sellScoresReturning(['score' => 5, 'avg_daily_volume' => 10.0, 'avg_order_count' => 5.0, 'active_days' => 20, 'total_days' => 30]));

        $mockCalculator = $this->mock(ManufacturingCalculatorService::class);
        $mockCalculator->shouldReceive('calculateMaterials')
            ->times(22)  // 2 items × 11 ME levels
            ->andReturn([['type_id' => 34, 'adjusted_quantity' => 100]]);

        $mockMarket = $this->mock(MarketService::class);
        // Prices fetched exactly once
        $mockMarket->shouldReceive('getSellPrices')
            ->once()
            ->andReturn([34 => 5.0, 587 => 100000.0, 585 => 80000.0]);

        $job = new PrecomputeManufacturingCostsChunk(10000002, [], true);
        $job->handle($mockBrowser, $mockCalculator, $mockMarket);

        // 2 items × 11 ME levels = 22 records
        $this->assertEquals(22, ManufacturingCost::count());
    }

    public function test_job_keeps_only_allowed_meta_groups(): void
    {
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'published' => true, 'meta_group_id' => EveType::META_GROUP_TECH_I]);
        EveType::create(['type_id' => 22228, 'name' => 'Wolf', 'published' => true, 'meta_group_id' => EveType::META_GROUP_TECH_II]);

        $items = [
            [
                'blueprint_type_id' => 687,
                'blueprint_name' => 'Rifter Blueprint',
                'materials' => [['type_id' => 34, 'quantity' => 100]],
                'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
            ],
            [
                'blueprint_type_id' => 22229,
                'blueprint_name' => 'Wolf Blueprint',
                'materials' => [['type_id' => 34, 'quantity' => 500]],
                'products' => [['type_id' => 22228, 'name' => 'Wolf', 'quantity' => 1]],
            ],
        ];

        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->once()->andReturn($items);
        $mockBrowser->shouldReceive('calculateSellScores')
            ->andReturnUsing($this->sellScoresReturning(['score' => 5, 'avg_daily_volume' => 10.0, 'avg_order_count' => 5.0, 'active_days' => 20, 'total_days' => 30]));

        $mockMarket = $this->mock(MarketService::class);
        $mockMarket->shouldReceive('getSellPrices')->once()->andReturn([34 => 5.0, 587 => 1000.0, 22228 => 9000.0]);

        $job = new PrecomputeManufacturingCostsChunk(10000002, [EveType::META_GROUP_TECH_I], false);
        $job->handle($mockBrowser, app(ManufacturingCalculatorService::class), $mockMarket);

        // Only the T1 Rifter, at 11 ME levels
        $this->assertEquals(11, ManufacturingCost::count());
        $this->assertEquals(0, ManufacturingCost::where('product_type_id', 22228)->count());
    }

    public function test_job_profit_includes_scc_fee_and_margin_is_on_input_cost(): void
    {
        $items = [
            [
                'blueprint_type_id' => 687,
                'blueprint_name' => 'Rifter Blueprint',
                'materials' => [['type_id' => 34, 'quantity' => 100]],
                'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
            ],
        ];

        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->once()->andReturn($items);
        $mockBrowser->shouldReceive('calculateSellScores')
            ->andReturnUsing($this->sellScoresReturning(['score' => 5, 'avg_daily_volume' => 10.0, 'avg_order_count' => 5.0, 'active_days' => 20, 'total_days' => 30]));

        $mockMarket = $this->mock(MarketService::class);
        $mockMarket->shouldReceive('getSellPrices')->once()->andReturn([34 => 10.0, 587 => 2000.0]);

        $job = new PrecomputeManufacturingCostsChunk(10000002, [], true);
        $job->handle($mockBrowser, app(ManufacturingCalculatorService::class), $mockMarket);

        $cost = ManufacturingCost::where('product_type_id', 587)->where('me_level', 0)->first();

        // Input 100 × 10 = 1000; output 2000; SCC 4% of 2000 = 80
        // Profit = 2000 - 1000 - 80 = 920; margin = 920 / 1000 = 92.0%
        $this->assertEqualsWithDelta(1000.0, $cost->input_cost, 0.001);
        $this->assertEqualsWithDelta(920.0, $cost->base_profit, 0.001);
        $this->assertEqualsWithDelta(92.0, $cost->profit_margin, 0.001);
    }

    public function test_calculator_and_precompute_agree_on_margin(): void
    {
        // Same item, ME level, region and prices through both paths
        $regionId = 10000043;
        $meLevel = 7;
        $baseMaterials = [
            ['type_id' => 34, 'quantity' => 2000, 'name' => 'Tritanium'],
            ['type_id' => 35, 'quantity' => 750, 'name' => 'Pyerite'],
            ['type_id' => 36, 'quantity' => 133, 'name' => 'Mexallon'],
        ];
        $items = [
            [
                'blueprint_type_id' => 687,
                'blueprint_name' => 'Rifter Blueprint',
                'materials' => $baseMaterials,
                'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
            ],
        ];
        $prices = [34 => 4.37, 35 => 11.92, 36 => 61.5, 587 => 41250.0];

        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->once()->andReturn($items);
        $mockBrowser->shouldReceive('calculateSellScores')
            ->andReturnUsing($this->sellScoresReturning(['score' => 5, 'avg_daily_volume' => 10.0, 'avg_order_count' => 5.0, 'active_days' => 20, 'total_days' => 30]));

        $mockMarket = $this->mock(MarketService::class);
        $mockMarket->shouldReceive('getSellPrices')->andReturn($prices);
        $mockMarket->shouldReceive('getSellPricesWithSource')->andReturn(
            array_map(fn ($p) => ['price' => $p, 'source' => MarketService::PRICE_SOURCE_ORDERS], $prices)
        );

        $calculator = new ManufacturingCalculatorService($this->mock(EsiService::class), $mockMarket);

        $job = new PrecomputeManufacturingCostsChunk($regionId, [], true);
        $job->handle($mockBrowser, $calculator, $mockMarket);
        $precomputed = ManufacturingCost::where('product_type_id', 587)
            ->where('region_id', $regionId)
            ->where('me_level', $meLevel)
            ->first();

        $materials = $calculator->calculateMaterials($baseMaterials, $meLevel, 1);
        $calculated = $calculator->calculateCostProfit($materials, 587, 1, 1, $regionId);

        $this->assertEqualsWithDelta($calculated['total_material_cost'], $precomputed->input_cost, 0.01);
        $this->assertEqualsWithDelta($calculated['profit'], $precomputed->base_profit, 0.01);
        // Within 0.1 percentage points
        $this->assertEqualsWithDelta($calculated['profit_margin'], $precomputed->profit_margin, 0.1);
        $this->assertNotEquals(0.0, $precomputed->profit_margin);
    }

    /**
     * Runs the job for one Rifter blueprint with mocked services.
     */
    private function runRifterJob(int $regionId = 10000002): void
    {
        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->andReturn([[
            'blueprint_type_id' => 687,
            'blueprint_name' => 'Rifter Blueprint',
            'materials' => [['type_id' => 34, 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]]);
        $mockBrowser->shouldReceive('calculateSellScores')
            ->andReturnUsing($this->sellScoresReturning(['score' => 5, 'avg_daily_volume' => 10.0, 'avg_order_count' => 5.0, 'active_days' => 20, 'total_days' => 30]));
        $mockCalculator = $this->mock(ManufacturingCalculatorService::class);
        $mockCalculator->shouldReceive('calculateMaterials')->andReturn([['type_id' => 34, 'adjusted_quantity' => 100]]);
        $mockMarket = $this->mock(MarketService::class);
        $mockMarket->shouldReceive('getSellPrices')->andReturn([34 => 5.0, 587 => 100000.0]);

        (new PrecomputeManufacturingCostsChunk($regionId, [], true))->handle($mockBrowser, $mockCalculator, $mockMarket);
    }

    public function test_job_stores_the_snapshot_its_prices_came_from(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));

        $this->runRifterJob();
        $this->assertNull(ManufacturingCost::first()->prices_as_of, 'No completed sweep yet');

        \App\Models\MarketOrderSweep::create([
            'sweep_id' => 's1', 'region_id' => 10000002, 'status' => \App\Models\MarketOrderSweep::STATUS_COMPLETED,
            'started_at' => '2026-10-22 11:45:00', 'finished_at' => '2026-10-22 11:50:00', 'duration_seconds' => 300,
        ]);
        // Past the cached "no snapshot yet" answer (a real sweep publishes its snapshot directly).
        $this->travel(MarketAggregateService::SNAPSHOT_CACHE_TTL + 1)->seconds();
        $this->runRifterJob();

        $this->assertSame(11, ManufacturingCost::count());
        $this->assertSame(['2026-10-22 11:45:00'], ManufacturingCost::pluck('prices_as_of')->map->toDateTimeString()->unique()->values()->all());
    }

    public function test_job_removes_rows_it_did_not_rewrite_in_its_region(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));
        $stale = fn (int $productTypeId, int $regionId) => ManufacturingCost::insert([
            'product_type_id' => $productTypeId, 'blueprint_type_id' => 1, 'region_id' => $regionId, 'me_level' => 0,
            'product_name' => 'Old', 'blueprint_name' => 'Old Blueprint', 'input_cost' => 1, 'output_value' => 2,
            'base_profit' => 1, 'profit_margin' => 100, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        // A product no longer built, in this region and in another one.
        $stale(999, 10000002);
        $stale(999, 10000043);

        $this->travel(1)->minutes();
        $this->runRifterJob(10000002);

        $this->assertSame(0, ManufacturingCost::where('region_id', 10000002)->where('product_type_id', 999)->count());
        $this->assertSame(11, ManufacturingCost::where('region_id', 10000002)->count());
        $this->assertSame(1, ManufacturingCost::where('region_id', 10000043)->count());
    }

    public function test_job_with_no_items_removes_nothing(): void
    {
        ManufacturingCost::insert([
            'product_type_id' => 999, 'blueprint_type_id' => 1, 'region_id' => 10000002, 'me_level' => 0,
            'product_name' => 'Old', 'blueprint_name' => 'Old Blueprint', 'input_cost' => 1, 'output_value' => 2,
            'base_profit' => 1, 'profit_margin' => 100, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);

        $mockBrowser = $this->mock(ManufacturingBrowserService::class);
        $mockBrowser->shouldReceive('getAllManufacturingBlueprints')->andReturn([]);

        (new PrecomputeManufacturingCostsChunk(10000002, [], true))->handle(
            $mockBrowser,
            $this->mock(ManufacturingCalculatorService::class),
            $this->mock(MarketService::class),
        );

        $this->assertSame(1, ManufacturingCost::count());
    }
}
