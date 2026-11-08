<?php

namespace Tests\Unit;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\InvCategory;
use App\Models\InvGroup;
use App\Models\ManufacturingCost;
use App\Models\MarketGroup;
use App\Models\MarketHistory;
use App\Models\MarketOrder;
use App\Models\MarketPrice;
use App\Services\EsiService;
use App\Services\ManufacturingBrowserService;
use App\Services\ManufacturingCalculatorService;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ManufacturingBrowserServiceTest extends TestCase
{
    use RefreshDatabase;

    private ManufacturingBrowserService $service;

    private EsiService|\Mockery\MockInterface $esiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esiMock = Mockery::mock(EsiService::class);
        $marketService = new MarketService($this->esiMock);
        $calculator = new ManufacturingCalculatorService($this->esiMock, $marketService);

        $this->service = new ManufacturingBrowserService($calculator, $marketService, $this->esiMock);
    }

    private function createSellOrder(int $typeId, float $price, int $volumeRemain = 1000, ?string $issued = null): void
    {
        $this->createSellOrderInRegion($typeId, $price, MarketService::DEFAULT_REGION_ID, $volumeRemain, $issued);
    }

    private function createSellOrderInRegion(int $typeId, float $price, int $regionId, int $volumeRemain = 1000, ?string $issued = null): void
    {
        static $orderId = 9000000;
        $orderId++;

        MarketOrder::create([
            'order_id' => $orderId,
            'region_id' => $regionId,
            'type_id' => $typeId,
            'is_buy_order' => false,
            'price' => $price,
            'volume_remain' => $volumeRemain,
            'volume_total' => $volumeRemain,
            'issued' => $issued ?? now()->toDateTimeString(),
        ]);
    }

    public function test_get_all_manufacturing_blueprints_returns_empty_when_no_data(): void
    {
        $result = $this->service->getAllManufacturingBlueprints();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_all_manufacturing_blueprints_returns_items_with_products(): void
    {
        EveType::create([
            'type_id' => 687,
            'name' => 'Rifter Blueprint',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 25000],
                ['type_id' => 35, 'name' => 'Pyerite', 'quantity' => 2000],
            ],
            'products' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
            ],
        ]);

        $result = $this->service->getAllManufacturingBlueprints();

        $this->assertCount(1, $result);
        $this->assertEquals(687, $result[0]['blueprint_type_id']);
        $this->assertEquals('Rifter Blueprint', $result[0]['blueprint_name']);
        $this->assertCount(2, $result[0]['materials']);
        $this->assertCount(1, $result[0]['products']);
        $this->assertEquals(3600, $result[0]['time']);
    }

    public function test_get_all_manufacturing_blueprints_skips_activities_without_products(): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => 99999,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [],
        ]);

        $result = $this->service->getAllManufacturingBlueprints();

        $this->assertEmpty($result);
    }

    public function test_get_all_manufacturing_blueprints_sorted_by_name(): void
    {
        EveType::create(['type_id' => 1, 'name' => 'Zydrine Module Blueprint', 'published' => true]);
        EveType::create(['type_id' => 2, 'name' => 'Alpha Module Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 100, 'name' => 'Mat A', 'quantity' => 10]],
            'products' => [['type_id' => 200, 'name' => 'Product Z', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 2,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 101, 'name' => 'Mat B', 'quantity' => 10]],
            'products' => [['type_id' => 201, 'name' => 'Product A', 'quantity' => 1]],
        ]);

        $result = $this->service->getAllManufacturingBlueprints();

        $this->assertCount(2, $result);
        $this->assertEquals('Alpha Module Blueprint', $result[0]['blueprint_name']);
        $this->assertEquals('Zydrine Module Blueprint', $result[1]['blueprint_name']);
    }

    public function test_does_not_include_reaction_activities(): void
    {
        EveType::create(['type_id' => 1, 'name' => 'Reaction Formula', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 100, 'name' => 'Moon Goo', 'quantity' => 1000]],
            'products' => [['type_id' => 200, 'name' => 'Composite', 'quantity' => 1]],
        ]);

        $result = $this->service->getAllManufacturingBlueprints();

        $this->assertEmpty($result);
    }

    public function test_calculate_all_profitability_returns_empty_when_no_items(): void
    {
        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result['items']);
        $this->assertEquals(0, $result['total']);
    }

    public function test_calculate_all_profitability_with_sell_orders(): void
    {
        EveType::create([
            'type_id' => 687,
            'name' => 'Rifter Blueprint',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 25000],
                ['type_id' => 35, 'name' => 'Pyerite', 'quantity' => 2000],
            ],
            'products' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
            ],
        ]);

        $this->createSellOrder(34, 5);
        $this->createSellOrder(35, 10);
        $this->createSellOrder(587, 500000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result['items']);

        $item = $result['items'][0];
        $this->assertEquals(687, $item['blueprint_type_id']);
        $this->assertEquals('Rifter', $item['product_name']);
        $this->assertEquals(587, $item['product_type_id']);
        $this->assertEquals(1, $item['total_output']);

        // Input cost: (25000 * 5) + (2000 * 10) = 125,000 + 20,000 = 145,000
        $this->assertEquals(145000, $item['total_input_cost']);

        // Output value: 1 * 500,000 = 500,000
        $this->assertEquals(500000, $item['total_output_value']);

        // Profit: 500,000 - 145,000 - 20,000 (SCC 4%) = 335,000
        $this->assertEquals(335000, $item['profit']);
        $this->assertTrue($item['profit_positive']);
    }

    public function test_calculate_all_profitability_with_me_level(): void
    {
        EveType::create([
            'type_id' => 687,
            'name' => 'Rifter Blueprint',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 1000],
            ],
            'products' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
            ],
        ]);

        $this->createSellOrder(34, 5);
        $this->createSellOrder(587, 500000);

        // ME 10 = 10% reduction: 1000 * 0.9 = 900
        $result = $this->service->calculateAllProfitability(runs: 1, meLevel: 10);

        $this->assertCount(1, $result['items']);
        // Input cost: 900 * 5 = 4,500
        $this->assertEquals(4500, $result['items'][0]['total_input_cost']);
    }

    public function test_calculate_all_profitability_with_structure_me_bonus(): void
    {
        EveType::create([
            'type_id' => 687,
            'name' => 'Rifter Blueprint',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 1000],
            ],
            'products' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
            ],
        ]);

        $this->createSellOrder(34, 5);
        $this->createSellOrder(587, 500000);

        // ME 0, structure bonus 2.0%: 1000 * 1.0 * 0.98 = 980
        $result = $this->service->calculateAllProfitability(runs: 1, meLevel: 0, structureMeBonus: 2.0);

        $this->assertCount(1, $result['items']);
        // Input cost: 980 * 5 = 4,900
        $this->assertEquals(4900, $result['items'][0]['total_input_cost']);
    }

    public function test_calculate_all_profitability_with_job_costs(): void
    {
        EveType::create([
            'type_id' => 687,
            'name' => 'Rifter Blueprint',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
            ],
        ]);

        $this->createSellOrder(34, 1000);
        $this->createSellOrder(587, 500000);

        $result = $this->service->calculateAllProfitability(
            runs: 1,
            meLevel: 0,
            structureMeBonus: 0.0,
            facilityTaxRate: 10.0,
            systemCostIndex: 5.0
        );

        $this->assertCount(1, $result['items']);
        $this->assertEquals(100000, $result['items'][0]['total_input_cost']);
        $this->assertEquals(5000, $result['items'][0]['job_fee']);
        $this->assertEquals(500, $result['items'][0]['facility_tax']);
        $this->assertEquals(5500, $result['items'][0]['total_job_cost']);
        // Profit: 500,000 - 100,000 - 5,500 - 20,000 (SCC 4%) = 374,500
        $this->assertEquals(374500, $result['items'][0]['profit']);
    }

    public function test_calculate_all_profitability_sorted_by_profit_desc(): void
    {
        EveType::create(['type_id' => 1, 'name' => 'Blueprint A', 'published' => true]);
        EveType::create(['type_id' => 2, 'name' => 'Blueprint B', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 100, 'name' => 'Mat A', 'quantity' => 10]],
            'products' => [['type_id' => 200, 'name' => 'Product A', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 2,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 101, 'name' => 'Mat B', 'quantity' => 10]],
            'products' => [['type_id' => 201, 'name' => 'Product B', 'quantity' => 1]],
        ]);

        $this->createSellOrder(100, 100);
        $this->createSellOrder(101, 100);
        $this->createSellOrder(200, 500);
        $this->createSellOrder(201, 5000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(2, $result['items']);
        $this->assertEquals('Product B', $result['items'][0]['product_name']);
        $this->assertEquals('Product A', $result['items'][1]['product_name']);
    }

    public function test_calculate_all_profitability_falls_back_to_market_prices(): void
    {
        EveType::create([
            'type_id' => 687,
            'name' => 'Rifter Blueprint',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
            ],
        ]);

        // No sell orders — falls back to market_prices
        MarketPrice::create(['type_id' => 34, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 5]);
        MarketPrice::create(['type_id' => 587, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 500000]);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result['items']);
        $this->assertEquals(500, $result['items'][0]['total_input_cost']);
        $this->assertEquals(500000, $result['items'][0]['total_output_value']);
    }

    public function test_sell_score_returns_zero_with_no_history(): void
    {
        $result = $this->service->calculateSellScore(587, 1);

        $this->assertEquals(0, $result['score']);
        $this->assertEquals(0, $result['avg_daily_volume']);
        $this->assertEquals(0, $result['avg_order_count']);
        $this->assertEquals(0, $result['active_days']);
        $this->assertEquals(0, $result['total_days']);
    }

    public function test_sell_score_high_volume_active_market(): void
    {
        for ($i = 0; $i < 30; $i++) {
            MarketHistory::create([
                'type_id' => 587,
                'region_id' => MarketService::DEFAULT_REGION_ID,
                'date' => now()->subDays($i)->toDateString(),
                'average' => 500000,
                'highest' => 550000,
                'lowest' => 450000,
                'volume' => 1000,
                'order_count' => 25,
            ]);
        }

        $result = $this->service->calculateSellScore(587, 1);

        $this->assertEquals(10, $result['score']);
        $this->assertEquals(1000, $result['avg_daily_volume']);
        $this->assertEquals(25, $result['avg_order_count']);
        $this->assertEquals(30, $result['active_days']);
        $this->assertEquals(30, $result['total_days']);
    }

    public function test_calculate_sell_scores_matches_calculate_sell_score(): void
    {
        $jita = MarketService::DEFAULT_REGION_ID;
        $amarr = 10000043;

        $addHistory = function (int $typeId, int $regionId, int $daysAgo, int $volume, int $orderCount): void {
            MarketHistory::create([
                'type_id' => $typeId,
                'region_id' => $regionId,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'average' => 100,
                'highest' => 110,
                'lowest' => 90,
                'volume' => $volume,
                'order_count' => $orderCount,
            ]);
        };

        // 1001: 60 days with gaps (every third day missing), so its latest 30
        // rows span more than 30 calendar days; some zero-volume days.
        for ($d = 0; $d < 60; $d++) {
            if ($d % 3 === 2) {
                continue;
            }
            $addHistory(1001, $jita, $d, $d % 7 === 0 ? 0 : 17 * $d + 3, $d % 11 + 1);
        }
        // 1002: fewer than 30 rows
        for ($d = 0; $d < 12; $d++) {
            $addHistory(1002, $jita, $d * 2, 400 + $d, 30);
        }
        // 1003: exactly 30 rows, all zero volume
        for ($d = 0; $d < 30; $d++) {
            $addHistory(1003, $jita, $d, 0, 0);
        }
        // 1004: 40 consecutive days, rising volume, so the cut-off matters
        for ($d = 0; $d < 40; $d++) {
            $addHistory(1004, $jita, $d, 1000 - 20 * $d, 6 + $d);
        }
        // 1005: history only in another region
        for ($d = 0; $d < 35; $d++) {
            $addHistory(1005, $amarr, $d, 50 + $d, 8);
        }
        // Other region rows for 1001 too, which must not leak into Jita
        for ($d = 0; $d < 30; $d++) {
            $addHistory(1001, $amarr, $d, 99999, 999);
        }
        // 1006: no history at all

        $requests = [
            'a' => ['type_id' => 1001, 'total_output' => 1],
            'b' => ['type_id' => 1001, 'total_output' => 250],
            'c' => ['type_id' => 1002, 'total_output' => 10],
            'd' => ['type_id' => 1003, 'total_output' => 1],
            'e' => ['type_id' => 1004, 'total_output' => 100],
            'f' => ['type_id' => 1005, 'total_output' => 5],
            'g' => ['type_id' => 1006, 'total_output' => 1],
            'h' => ['type_id' => 1004, 'total_output' => 0],
        ];

        foreach ([$jita, $amarr] as $regionId) {
            $batch = $this->service->calculateSellScores($requests, $regionId);

            $this->assertSame(array_keys($requests), array_keys($batch));
            foreach ($requests as $key => $request) {
                $this->assertSame(
                    $this->service->calculateSellScore($request['type_id'], $request['total_output'], $regionId),
                    $batch[$key],
                    "Sell score for {$key} in region {$regionId}"
                );
            }
        }

        // The data is varied enough to give non-trivial scores
        $jitaScores = $this->service->calculateSellScores($requests, $jita);
        $this->assertSame(30, $jitaScores['a']['total_days']);
        $this->assertLessThan(30, $jitaScores['a']['active_days']);
        $this->assertSame(12, $jitaScores['c']['total_days']);
        $this->assertGreaterThan(0, $jitaScores['e']['score']);
        $this->assertNotEquals($jitaScores['a']['score'], $jitaScores['b']['score']);
    }

    public function test_calculate_sell_scores_with_no_products_returns_empty(): void
    {
        $this->assertSame([], $this->service->calculateSellScores([]));
    }

    public function test_sell_score_included_in_profitability_results(): void
    {
        EveType::create([
            'type_id' => 687,
            'name' => 'Rifter Blueprint',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1],
            ],
        ]);

        $this->createSellOrder(34, 5);
        $this->createSellOrder(587, 500000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result['items']);
        $this->assertArrayHasKey('sell_score', $result['items'][0]);
        $this->assertArrayHasKey('sell_score_avg_daily_volume', $result['items'][0]);
        $this->assertArrayHasKey('sell_score_avg_order_count', $result['items'][0]);
        $this->assertArrayHasKey('sell_score_active_days', $result['items'][0]);
        $this->assertArrayHasKey('sell_score_total_days', $result['items'][0]);
        $this->assertIsInt($result['items'][0]['sell_score']);
        $this->assertGreaterThanOrEqual(0, $result['items'][0]['sell_score']);
        $this->assertLessThanOrEqual(10, $result['items'][0]['sell_score']);
    }

    // ── Category filtering tests ──────────────────────────────────────

    public function test_get_available_product_categories_returns_empty_when_no_data(): void
    {
        $result = $this->service->getAvailableProductCategories();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_available_product_categories_returns_distinct_categories(): void
    {
        // Two products in group 25 (Frigate, category 6=Ship), one in group 74 (Module, category 7=Module)
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'group_id' => 25, 'published' => true]);
        EveType::create(['type_id' => 588, 'name' => 'Slasher', 'group_id' => 25, 'published' => true]);
        EveType::create(['type_id' => 3001, 'name' => 'Some Module', 'group_id' => 74, 'published' => true]);

        // Blueprints
        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);
        EveType::create(['type_id' => 688, 'name' => 'Slasher Blueprint', 'published' => true]);
        EveType::create(['type_id' => 3002, 'name' => 'Some Module Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 688,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 588, 'name' => 'Slasher', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 3002,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 50]],
            'products' => [['type_id' => 3001, 'name' => 'Some Module', 'quantity' => 1]],
        ]);

        // Mock ESI: group 25 → category 6 (Ship), group 74 → category 7 (Module)
        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);
        InvGroup::create(['group_id' => 74, 'name' => 'Hybrid Weapon', 'category_id' => 7, 'published' => true]);

        // Category info
        InvCategory::create(['category_id' => 7, 'name' => 'Module', 'published' => true]);
        InvCategory::create(['category_id' => 6, 'name' => 'Ship', 'published' => true]);

        $result = $this->service->getAvailableProductCategories();

        $this->assertCount(2, $result);
        // Sorted by name
        $this->assertEquals('Module', $result[0]['name']);
        $this->assertEquals(7, $result[0]['category_id']);
        $this->assertEquals(1, $result[0]['item_count']);
        $this->assertEquals('Ship', $result[1]['name']);
        $this->assertEquals(6, $result[1]['category_id']);
        $this->assertEquals(2, $result[1]['item_count']);
    }

    public function test_get_available_product_categories_aggregates_multiple_groups_in_same_category(): void
    {
        // Products in different groups but same category (Ship, category 6)
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'group_id' => 25, 'published' => true]);
        EveType::create(['type_id' => 621, 'name' => 'Stabber', 'group_id' => 26, 'published' => true]);

        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);
        EveType::create(['type_id' => 721, 'name' => 'Stabber Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 721,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 200]],
            'products' => [['type_id' => 621, 'name' => 'Stabber', 'quantity' => 1]],
        ]);

        // Both groups belong to category 6 (Ship)
        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);
        InvGroup::create(['group_id' => 26, 'name' => 'Cruiser', 'category_id' => 6, 'published' => true]);

        InvCategory::create(['category_id' => 6, 'name' => 'Ship', 'published' => true]);

        $result = $this->service->getAvailableProductCategories();

        // Both groups aggregated into single "Ship" category
        $this->assertCount(1, $result);
        $this->assertEquals('Ship', $result[0]['name']);
        $this->assertEquals(6, $result[0]['category_id']);
        $this->assertEquals(2, $result[0]['item_count']);
    }

    public function test_get_all_manufacturing_blueprints_filters_by_category_id(): void
    {
        // Products with different groups in different categories
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'group_id' => 25, 'published' => true]);
        EveType::create(['type_id' => 3001, 'name' => 'Some Module', 'group_id' => 74, 'published' => true]);

        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);
        EveType::create(['type_id' => 3002, 'name' => 'Some Module Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 3002,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 50]],
            'products' => [['type_id' => 3001, 'name' => 'Some Module', 'quantity' => 1]],
        ]);

        // group 25 → category 6 (Ship), group 74 → category 7 (Module)
        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);
        InvGroup::create(['group_id' => 74, 'name' => 'Hybrid Weapon', 'category_id' => 7, 'published' => true]);

        // Without category filter - returns all
        $result = $this->service->getAllManufacturingBlueprints();
        $this->assertCount(2, $result);

        // With category 6 (Ship) filter - returns only Rifter
        $result = $this->service->getAllManufacturingBlueprints(categoryId: 6);
        $this->assertCount(1, $result);
        $this->assertEquals('Rifter Blueprint', $result[0]['blueprint_name']);

        // With category 7 (Module) filter - returns only the module
        $result = $this->service->getAllManufacturingBlueprints(categoryId: 7);
        $this->assertCount(1, $result);
        $this->assertEquals('Some Module Blueprint', $result[0]['blueprint_name']);

        // With non-existing category - returns empty
        $result = $this->service->getAllManufacturingBlueprints(categoryId: 99999);
        $this->assertEmpty($result);
    }

    public function test_calculate_all_profitability_with_category_filter(): void
    {
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'group_id' => 25, 'published' => true]);
        EveType::create(['type_id' => 3001, 'name' => 'Some Module', 'group_id' => 74, 'published' => true]);

        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);
        EveType::create(['type_id' => 3002, 'name' => 'Some Module Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 3002,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 50]],
            'products' => [['type_id' => 3001, 'name' => 'Some Module', 'quantity' => 1]],
        ]);

        // group 25 → category 6 (Ship), group 74 → category 7 (Module)
        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);
        InvGroup::create(['group_id' => 74, 'name' => 'Hybrid Weapon', 'category_id' => 7, 'published' => true]);

        $this->createSellOrder(34, 5);
        $this->createSellOrder(587, 500000);
        $this->createSellOrder(3001, 50000);

        // Filter by category 6 (Ship) - only Rifter
        $result = $this->service->calculateAllProfitability(runs: 1, categoryId: 6);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('Rifter', $result['items'][0]['product_name']);

        // Filter by category 7 (Module) - only Module
        $result = $this->service->calculateAllProfitability(runs: 1, categoryId: 7);
        $this->assertCount(1, $result['items']);
        $this->assertEquals('Some Module', $result['items'][0]['product_name']);
    }

    // ── T1-only filtering (excludes invented blueprints) ──────────────

    public function test_get_all_manufacturing_blueprints_excludes_invented_blueprints(): void
    {
        // T1 blueprint (Rifter) — not produced by invention
        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        // T1 blueprint's invention activity → produces T2 blueprint 22229
        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_INVENTION,
            'time' => 105600,
            'materials' => [
                ['type_id' => 20176, 'name' => 'Datacore', 'quantity' => 2],
            ],
            'products' => [
                ['type_id' => 22229, 'name' => 'Wolf Blueprint', 'quantity' => 10, 'probability' => 0.34],
            ],
        ]);

        // T2 blueprint (Wolf) — produced by invention, should be excluded
        EveType::create(['type_id' => 22229, 'name' => 'Wolf Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 22229,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 7200,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500]],
            'products' => [['type_id' => 22228, 'name' => 'Wolf', 'quantity' => 1]],
        ]);

        $result = $this->service->getAllManufacturingBlueprints();

        // Only the T1 Rifter should be included, Wolf (T2) should be excluded
        $this->assertCount(1, $result);
        $this->assertEquals('Rifter Blueprint', $result[0]['blueprint_name']);
    }

    public function test_calculate_all_profitability_excludes_invented_blueprints(): void
    {
        // T1 blueprint
        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        // Invention activity that produces a T2 blueprint
        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_INVENTION,
            'time' => 105600,
            'materials' => [['type_id' => 20176, 'name' => 'Datacore', 'quantity' => 2]],
            'products' => [['type_id' => 22229, 'name' => 'Wolf Blueprint', 'quantity' => 10, 'probability' => 0.34]],
        ]);

        // T2 blueprint (should be excluded)
        EveType::create(['type_id' => 22229, 'name' => 'Wolf Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 22229,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 7200,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500]],
            'products' => [['type_id' => 22228, 'name' => 'Wolf', 'quantity' => 1]],
        ]);

        $this->createSellOrder(34, 5);
        $this->createSellOrder(587, 500000);
        $this->createSellOrder(22228, 800000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        // Only the T1 Rifter should be included
        $this->assertCount(1, $result['items']);
        $this->assertEquals('Rifter', $result['items'][0]['product_name']);
    }

    public function test_get_available_product_categories_excludes_invented_blueprints(): void
    {
        // T1 product
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'group_id' => 25, 'published' => true]);
        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        // Invention produces T2 blueprint
        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_INVENTION,
            'time' => 105600,
            'materials' => [['type_id' => 20176, 'name' => 'Datacore', 'quantity' => 2]],
            'products' => [['type_id' => 22229, 'name' => 'Wolf Blueprint', 'quantity' => 10, 'probability' => 0.34]],
        ]);

        // T2 product (should be excluded from category counts)
        EveType::create(['type_id' => 22228, 'name' => 'Wolf', 'group_id' => 25, 'published' => true]);
        EveType::create(['type_id' => 22229, 'name' => 'Wolf Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 22229,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 7200,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500]],
            'products' => [['type_id' => 22228, 'name' => 'Wolf', 'quantity' => 1]],
        ]);

        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);
        InvCategory::create(['category_id' => 6, 'name' => 'Ship', 'published' => true]);

        $result = $this->service->getAvailableProductCategories();

        // Only T1 Rifter counted, not T2 Wolf
        $this->assertCount(1, $result);
        $this->assertEquals('Ship', $result[0]['name']);
        $this->assertEquals(1, $result[0]['item_count']);
    }

    // ── Market group filtering tests ──────────────────────────────────

    public function test_collect_market_group_type_ids_returns_types_in_group(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 603, 'name' => 'Merlin', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 9999, 'name' => 'Unpublished', 'market_group_id' => 100, 'published' => false]);

        $typeIds = $this->service->collectMarketGroupTypeIds(100);

        $this->assertCount(2, $typeIds);
        $this->assertContains(587, $typeIds);
        $this->assertContains(603, $typeIds);
    }

    public function test_collect_market_group_type_ids_includes_descendants(): void
    {
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);
        MarketGroup::create([
            'market_group_id' => 101,
            'name' => 'Destroyers',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 621, 'name' => 'Thrasher', 'market_group_id' => 101, 'published' => true]);

        $typeIds = $this->service->collectMarketGroupTypeIds(2);

        $this->assertCount(2, $typeIds);
        $this->assertContains(587, $typeIds);
        $this->assertContains(621, $typeIds);
    }

    public function test_get_manufacturing_blueprints_for_market_group(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        // Another blueprint in a different group should NOT be included
        EveType::create(['type_id' => 3001, 'name' => 'Some Module', 'market_group_id' => 999, 'published' => true]);
        EveType::create(['type_id' => 3002, 'name' => 'Some Module Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 3002,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 50]],
            'products' => [['type_id' => 3001, 'name' => 'Some Module', 'quantity' => 1]],
        ]);

        $result = $this->service->getManufacturingBlueprintsForMarketGroup(100);

        $this->assertCount(1, $result);
        $this->assertEquals('Rifter Blueprint', $result[0]['blueprint_name']);
    }

    public function test_calculate_all_profitability_with_market_group_id(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        $this->createSellOrder(34, 5);
        $this->createSellOrder(587, 500000);

        $result = $this->service->calculateAllProfitability(runs: 1, marketGroupId: 100);

        $this->assertCount(1, $result['items']);
        $this->assertEquals('Rifter', $result['items'][0]['product_name']);
        $this->assertEquals(1, $result['total']);
    }

    public function test_calculate_all_profitability_with_nonexistent_market_group(): void
    {
        $result = $this->service->calculateAllProfitability(runs: 1, marketGroupId: 99999);

        $this->assertEmpty($result['items']);
        $this->assertEquals(0, $result['total']);
    }

    // ── Pagination tests ──────────────────────────────────────────────

    public function test_calculate_all_profitability_pagination_metadata(): void
    {
        EveType::create(['type_id' => 687, 'name' => 'Blueprint A', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Product A', 'quantity' => 1]],
        ]);

        $this->createSellOrder(34, 5);
        $this->createSellOrder(587, 500000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('page', $result);
        $this->assertArrayHasKey('per_page', $result);
        $this->assertArrayHasKey('last_page', $result);
        $this->assertEquals(1, $result['total']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(100, $result['per_page']);
        $this->assertEquals(1, $result['last_page']);
    }

    public function test_calculate_all_profitability_paginates_results(): void
    {
        // Create 3 blueprints, request page size of 2
        for ($i = 1; $i <= 3; $i++) {
            EveType::create(['type_id' => $i, 'name' => "Blueprint {$i}", 'published' => true]);

            BlueprintActivity::create([
                'blueprint_type_id' => $i,
                'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
                'time' => 3600,
                'materials' => [['type_id' => 100 + $i, 'name' => "Mat {$i}", 'quantity' => 10]],
                'products' => [['type_id' => 200 + $i, 'name' => "Product {$i}", 'quantity' => 1]],
            ]);

            $this->createSellOrder(100 + $i, 10);
            $this->createSellOrder(200 + $i, 1000 * $i);
        }

        // Page 1 with perPage=2
        $result = $this->service->calculateAllProfitability(runs: 1, page: 1, perPage: 2);
        $this->assertCount(2, $result['items']);
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(2, $result['last_page']);

        // Page 2 with perPage=2
        $result = $this->service->calculateAllProfitability(runs: 1, page: 2, perPage: 2);
        $this->assertCount(1, $result['items']);
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(2, $result['page']);
        $this->assertEquals(2, $result['last_page']);
    }

    // ── Precomputed browse tests ──────────────────────────────────────

    public function test_browse_precomputed_returns_empty_when_no_data(): void
    {
        $result = $this->service->browsePrecomputed(marketGroupId: 999);

        $this->assertIsArray($result);
        $this->assertEmpty($result['items']);
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(1, $result['page']);
        $this->assertEquals(100, $result['per_page']);
        $this->assertEquals(1, $result['last_page']);
    }

    /**
     * The browser page used to compute these fields in JavaScript
     * (applyUserAdjustments in manufacturing-browser.blade.php). This is a
     * line-by-line PHP copy of that code, kept to prove the server matches.
     */
    private function oldBrowserJsFormula(array $item, int $runs, float $structureMeBonus, float $facilityTaxRate, float $systemCostIndex): array
    {
        $structureFactor = 1.0 - ($structureMeBonus / 100.0);
        $adjustedInputCost = $item['input_cost'] * $runs * $structureFactor;
        $totalOutput = $runs * $item['portion_size'];
        $totalOutputValue = $item['output_value'] * $runs;
        $jobFee = $adjustedInputCost * ($systemCostIndex / 100);
        $facilityTax = $jobFee * ($facilityTaxRate / 100);
        $totalJobCost = $jobFee + $facilityTax;
        $sccFee = $totalOutputValue * 0.04;
        $profit = $totalOutputValue - $adjustedInputCost - $totalJobCost - $sccFee;
        // JS: Math.round(profit / adjustedInputCost * 1000) / 10
        $profitMargin = $adjustedInputCost > 0 ? floor($profit / $adjustedInputCost * 1000 + 0.5) / 10 : 0;
        $profitPerUnit = $totalOutput > 0 ? $profit / $totalOutput : 0;

        return [
            'total_output' => $totalOutput,
            'total_input_cost' => $adjustedInputCost,
            'total_output_value' => $totalOutputValue,
            'total_job_cost' => $totalJobCost,
            'scc_fee' => $sccFee,
            'profit' => $profit,
            'profit_positive' => $profit >= 0,
            'profit_per_unit' => $profitPerUnit,
            'profit_margin' => $profitMargin,
        ];
    }

    public function test_apply_user_adjustments_matches_old_browser_js_formula(): void
    {
        $samples = [
            // [input_cost, output_value, portion_size, runs, structure ME bonus, facility tax %, system cost index %]
            [123456.78, 250000.0, 3, 7, 2.4 * 2.1, 10.0, 4.37],
            [100000.0, 500000.0, 1, 1, 0.0, 0.0, 0.0],
            [980000.0, 900000.0, 100, 25, 2.0 * 1.9, 0.25, 12.5],
            [0.0, 1500.0, 1, 3, 0.0, 5.0, 1.0],
        ];

        foreach ($samples as [$input, $output, $portion, $runs, $me, $tax, $sci]) {
            $expected = $this->oldBrowserJsFormula(
                ['input_cost' => $input, 'output_value' => $output, 'portion_size' => $portion],
                $runs, $me, $tax, $sci
            );
            $actual = ManufacturingBrowserService::applyUserAdjustments($input, $output, $portion, $runs, $me, $tax, $sci);

            foreach ($expected as $key => $value) {
                if (is_bool($value)) {
                    $this->assertSame($value, $actual[$key], "{$key} for input {$input}");
                } else {
                    $this->assertEqualsWithDelta($value, $actual[$key], 0.0001, "{$key} for input {$input}");
                }
            }
        }
    }

    public function test_apply_user_adjustments_worked_example(): void
    {
        // 10 runs, 4% structure ME, input 1,000/run, output 2,000/run, portion 1
        // Input = 1,000 × 10 × 0.96 = 9,600; output = 20,000
        // Job fee = 9,600 × 5% = 480; facility tax = 480 × 10% = 48; total 528
        // SCC = 4% of 20,000 = 800; profit = 20,000 - 9,600 - 528 - 800 = 9,072
        // Margin = 9,072 / 9,600 = 94.5%
        $r = ManufacturingBrowserService::applyUserAdjustments(1000.0, 2000.0, 1, 10, 4.0, 10.0, 5.0);

        $this->assertEqualsWithDelta(9600.0, $r['total_input_cost'], 0.001);
        $this->assertEqualsWithDelta(20000.0, $r['total_output_value'], 0.001);
        $this->assertEqualsWithDelta(480.0, $r['job_fee'], 0.001);
        $this->assertEqualsWithDelta(48.0, $r['facility_tax'], 0.001);
        $this->assertEqualsWithDelta(528.0, $r['total_job_cost'], 0.001);
        $this->assertEqualsWithDelta(800.0, $r['scc_fee'], 0.001);
        $this->assertEqualsWithDelta(9072.0, $r['profit'], 0.001);
        $this->assertEqualsWithDelta(907.2, $r['profit_per_unit'], 0.001);
        $this->assertEquals(94.5, $r['profit_margin']);
        $this->assertEquals('9.07K', $r['profit_formatted']);
        $this->assertEquals('800.00', $r['scc_fee_formatted']);
    }

    public function test_browse_precomputed_applies_user_settings(): void
    {
        MarketGroup::create(['market_group_id' => 100, 'name' => 'Frigates', 'parent_group_id' => 2, 'has_types' => true]);
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        ManufacturingCost::create([
            'product_type_id' => 587,
            'blueprint_type_id' => 687,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'me_level' => 0,
            'product_name' => 'Rifter',
            'blueprint_name' => 'Rifter Blueprint',
            'portion_size' => 1,
            'input_cost' => 1000,
            'output_value' => 2000,
            'base_profit' => 920,
            'profit_margin' => 92.0,
            'sell_score' => 7,
            'sell_score_avg_daily_volume' => 50.0,
            'sell_score_avg_order_count' => 10.0,
            'sell_score_active_days' => 28,
            'sell_score_total_days' => 30,
        ]);

        $default = $this->service->browsePrecomputed(marketGroupId: 100)['items'][0];
        // With 1 run and no bonus or fees, the adjusted margin equals the stored one
        $this->assertEquals(92.0, $default['profit_margin']);
        $this->assertEquals(92.0, $default['base_profit_margin']);
        $this->assertEqualsWithDelta(920.0, $default['profit'], 0.001);

        $item = $this->service->browsePrecomputed(
            marketGroupId: 100,
            runs: 10,
            structureMeBonus: 4.0,
            facilityTaxRate: 10.0,
            systemCostIndex: 5.0
        )['items'][0];

        $this->assertEquals(10, $item['total_output']);
        $this->assertEqualsWithDelta(9072.0, $item['profit'], 0.001);
        $this->assertEquals(94.5, $item['profit_margin']);
        $this->assertEquals(92.0, $item['base_profit_margin']);
        $this->assertEquals(1000, $item['input_cost']);
    }

    public function test_browse_precomputed_returns_precomputed_items_for_market_group(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);

        ManufacturingCost::create([
            'product_type_id' => 587,
            'blueprint_type_id' => 687,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'me_level' => 0,
            'product_name' => 'Rifter',
            'blueprint_name' => 'Rifter Blueprint',
            'portion_size' => 1,
            'input_cost' => 100000,
            'output_value' => 500000,
            'base_profit' => 400000,
            'profit_margin' => 400.0,
            'sell_score' => 7,
            'sell_score_avg_daily_volume' => 50.0,
            'sell_score_avg_order_count' => 10.0,
            'sell_score_active_days' => 28,
            'sell_score_total_days' => 30,
        ]);

        $result = $this->service->browsePrecomputed(
            marketGroupId: 100,
            regionId: MarketService::DEFAULT_REGION_ID,
            meLevel: 0
        );

        $this->assertCount(1, $result['items']);
        $this->assertEquals(1, $result['total']);
        $this->assertEquals('Rifter', $result['items'][0]['product_name']);
        $this->assertEquals(100000, $result['items'][0]['input_cost']);
        $this->assertEquals(500000, $result['items'][0]['output_value']);
        $this->assertEquals(400000, $result['items'][0]['base_profit']);
        $this->assertTrue($result['items'][0]['base_profit_positive']);
        $this->assertEquals(7, $result['items'][0]['sell_score']);
    }

    public function test_browse_precomputed_filters_by_me_level(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);

        // ME 0 entry
        ManufacturingCost::create([
            'product_type_id' => 587,
            'blueprint_type_id' => 687,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'me_level' => 0,
            'product_name' => 'Rifter',
            'blueprint_name' => 'Rifter Blueprint',
            'portion_size' => 1,
            'input_cost' => 100000,
            'output_value' => 500000,
            'base_profit' => 400000,
            'profit_margin' => 400.0,
        ]);

        // ME 10 entry (lower input cost)
        ManufacturingCost::create([
            'product_type_id' => 587,
            'blueprint_type_id' => 687,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'me_level' => 10,
            'product_name' => 'Rifter',
            'blueprint_name' => 'Rifter Blueprint',
            'portion_size' => 1,
            'input_cost' => 90000,
            'output_value' => 500000,
            'base_profit' => 410000,
            'profit_margin' => 455.6,
        ]);

        $result0 = $this->service->browsePrecomputed(marketGroupId: 100, meLevel: 0);
        $result10 = $this->service->browsePrecomputed(marketGroupId: 100, meLevel: 10);

        $this->assertEquals(100000, $result0['items'][0]['input_cost']);
        $this->assertEquals(90000, $result10['items'][0]['input_cost']);
    }

    public function test_browse_precomputed_paginates_results(): void
    {
        MarketGroup::create([
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);

        for ($i = 1; $i <= 3; $i++) {
            EveType::create(['type_id' => $i, 'name' => "Ship {$i}", 'market_group_id' => 100, 'published' => true]);

            ManufacturingCost::create([
                'product_type_id' => $i,
                'blueprint_type_id' => 100 + $i,
                'region_id' => MarketService::DEFAULT_REGION_ID,
                'me_level' => 0,
                'product_name' => "Ship {$i}",
                'blueprint_name' => "Ship {$i} Blueprint",
                'portion_size' => 1,
                'input_cost' => 1000 * $i,
                'output_value' => 5000 * $i,
                'base_profit' => 4000 * $i,
                'profit_margin' => 400.0,
            ]);
        }

        $page1 = $this->service->browsePrecomputed(marketGroupId: 100, perPage: 2, page: 1);
        $this->assertCount(2, $page1['items']);
        $this->assertEquals(3, $page1['total']);
        $this->assertEquals(2, $page1['last_page']);

        $page2 = $this->service->browsePrecomputed(marketGroupId: 100, perPage: 2, page: 2);
        $this->assertCount(1, $page2['items']);
        $this->assertEquals(2, $page2['page']);
    }

    // ── T2 Module and T2/T3 Ship exclusion tests ──────────────────────

    public function test_get_all_manufacturing_blueprints_excludes_t2_modules(): void
    {
        // Set up inv_groups: group 25 = Ship (cat 6), group 74 = Module (cat 7)
        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);
        InvGroup::create(['group_id' => 74, 'name' => 'Hybrid Weapon', 'category_id' => 7, 'published' => true]);

        // T1 ship
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'group_id' => 25, 'meta_group_id' => EveType::META_GROUP_TECH_I, 'published' => true]);
        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);

        // T2 module (should be excluded)
        EveType::create(['type_id' => 3001, 'name' => 'T2 Module', 'group_id' => 74, 'meta_group_id' => EveType::META_GROUP_TECH_II, 'published' => true]);
        EveType::create(['type_id' => 3002, 'name' => 'T2 Module Blueprint', 'published' => true]);

        // T1 module (should be included)
        EveType::create(['type_id' => 3003, 'name' => 'T1 Module', 'group_id' => 74, 'meta_group_id' => EveType::META_GROUP_TECH_I, 'published' => true]);
        EveType::create(['type_id' => 3004, 'name' => 'T1 Module Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 3002,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 50]],
            'products' => [['type_id' => 3001, 'name' => 'T2 Module', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 3004,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 30]],
            'products' => [['type_id' => 3003, 'name' => 'T1 Module', 'quantity' => 1]],
        ]);

        $result = $this->service->getAllManufacturingBlueprints();

        // T2 Module should be excluded, T1 ship and T1 module included
        $blueprintNames = array_column($result, 'blueprint_name');
        $this->assertContains('Rifter Blueprint', $blueprintNames);
        $this->assertContains('T1 Module Blueprint', $blueprintNames);
        $this->assertNotContains('T2 Module Blueprint', $blueprintNames);
    }

    public function test_get_all_manufacturing_blueprints_excludes_t2_and_t3_ships(): void
    {
        // Set up inv_groups: group 25 = Ship (cat 6)
        InvGroup::create(['group_id' => 25, 'name' => 'Frigate', 'category_id' => 6, 'published' => true]);

        // T1 ship (included)
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'group_id' => 25, 'meta_group_id' => EveType::META_GROUP_TECH_I, 'published' => true]);
        EveType::create(['type_id' => 687, 'name' => 'Rifter Blueprint', 'published' => true]);

        // T2 ship (excluded)
        EveType::create(['type_id' => 22228, 'name' => 'Wolf', 'group_id' => 25, 'meta_group_id' => EveType::META_GROUP_TECH_II, 'published' => true]);
        EveType::create(['type_id' => 22229, 'name' => 'Wolf Blueprint', 'published' => true]);

        // T3 ship (excluded)
        EveType::create(['type_id' => 29984, 'name' => 'Tengu', 'group_id' => 25, 'meta_group_id' => EveType::META_GROUP_TECH_III, 'published' => true]);
        EveType::create(['type_id' => 29985, 'name' => 'Tengu Blueprint', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 687,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
            'products' => [['type_id' => 587, 'name' => 'Rifter', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 22229,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 7200,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500]],
            'products' => [['type_id' => 22228, 'name' => 'Wolf', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 29985,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 10000,
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 800]],
            'products' => [['type_id' => 29984, 'name' => 'Tengu', 'quantity' => 1]],
        ]);

        $result = $this->service->getAllManufacturingBlueprints();

        // Only T1 ship should be included
        $this->assertCount(1, $result);
        $this->assertEquals('Rifter Blueprint', $result[0]['blueprint_name']);
    }
}
