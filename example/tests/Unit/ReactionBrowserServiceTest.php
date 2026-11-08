<?php

namespace Tests\Unit;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\MarketHistory;
use App\Models\MarketOrder;
use App\Models\MarketPrice;
use App\Services\EsiService;
use App\Services\MarketService;
use App\Services\MarketTradingService;
use App\Services\ReactionBrowserService;
use App\Services\ReactionsCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReactionBrowserServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReactionBrowserService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $esiMock = Mockery::mock(EsiService::class);
        $marketService = new MarketService($esiMock);
        $calculator = new ReactionsCalculatorService($esiMock, $marketService);

        $this->service = new ReactionBrowserService($calculator, $marketService, $esiMock);
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

    public function test_get_all_reactions_returns_empty_when_no_data(): void
    {
        $result = $this->service->getAllReactions();

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_get_all_reactions_returns_reactions_with_products(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
                ['type_id' => 16635, 'name' => 'Evaporite Deposits', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200],
            ],
        ]);

        $result = $this->service->getAllReactions();

        $this->assertCount(1, $result);
        $this->assertEquals(46166, $result[0]['blueprint_type_id']);
        $this->assertEquals('Fernite Carbide Reaction Formula', $result[0]['formula_name']);
        $this->assertCount(2, $result[0]['materials']);
        $this->assertCount(1, $result[0]['products']);
        $this->assertEquals(3600, $result[0]['time']);
    }

    public function test_get_all_reactions_skips_activities_without_products(): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => 99999,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Test', 'quantity' => 100],
            ],
            'products' => [],
        ]);

        $result = $this->service->getAllReactions();

        $this->assertEmpty($result);
    }

    public function test_get_all_reactions_sorted_by_name(): void
    {
        EveType::create(['type_id' => 1, 'name' => 'Zydrine Formula', 'published' => true]);
        EveType::create(['type_id' => 2, 'name' => 'Alpha Formula', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 100, 'name' => 'Mat A', 'quantity' => 10]],
            'products' => [['type_id' => 200, 'name' => 'Product Z', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 2,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 101, 'name' => 'Mat B', 'quantity' => 10]],
            'products' => [['type_id' => 201, 'name' => 'Product A', 'quantity' => 1]],
        ]);

        $result = $this->service->getAllReactions();

        $this->assertCount(2, $result);
        $this->assertEquals('Alpha Formula', $result[0]['formula_name']);
        $this->assertEquals('Zydrine Formula', $result[1]['formula_name']);
    }

    public function test_calculate_all_profitability_returns_empty_when_no_reactions(): void
    {
        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_calculate_all_profitability_with_sell_orders(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
                ['type_id' => 16635, 'name' => 'Evaporite Deposits', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200],
            ],
        ]);

        $this->createSellOrder(16634, 500);
        $this->createSellOrder(16635, 600);
        $this->createSellOrder(16672, 1000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result);

        $reaction = $result[0];
        $this->assertEquals(46166, $reaction['blueprint_type_id']);
        $this->assertEquals('Fernite Carbide', $reaction['product_name']);
        $this->assertEquals(16672, $reaction['product_type_id']);
        $this->assertEquals(200, $reaction['total_output']);

        // Input cost: (100 * 500) + (100 * 600) = 110,000
        $this->assertEquals(110000, $reaction['total_input_cost']);

        // Output value: 200 * 1000 = 200,000
        $this->assertEquals(200000, $reaction['total_output_value']);

        // SCC fee: 4% of 200,000 = 8,000
        $this->assertEquals(8000, $reaction['scc_fee']);

        // Profit: 200,000 - 110,000 - 8,000 = 82,000
        $this->assertEquals(82000, $reaction['profit']);
        $this->assertTrue($reaction['profit_positive']);

        // Profit margin: profit / input_cost (matches reaction planner formula)
        // 82,000 / 110,000 = 74.5%
        $this->assertEquals(74.5, $reaction['profit_margin']);
    }

    public function test_calculate_all_profitability_uses_average_sell_five_percent(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200],
            ],
        ]);

        // Material: total vol = 1000, 5% = 50 units
        // Sorted: 100@50, 200@50, 500@900
        // 50 units all from first order → avg = 100
        $this->createSellOrder(16634, 100, 50);
        $this->createSellOrder(16634, 200, 50);
        $this->createSellOrder(16634, 500, 900);
        // Product: single order
        $this->createSellOrder(16672, 1000, 1000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result);
        // Material cost: 100 * 100 = 10,000 (avg sell 5%)
        $this->assertEquals(10000, $result[0]['total_input_cost']);
        // Output: 200 * 1000 = 200,000
        $this->assertEquals(200000, $result[0]['total_output_value']);
    }

    public function test_calculate_all_profitability_includes_orders_from_all_recent_dates(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200],
            ],
        ]);

        // Orders from different days — all should be included
        $threeDaysAgo = now()->subDays(3)->toDateTimeString();
        $this->createSellOrder(16634, 500, 1000, $threeDaysAgo);
        $this->createSellOrder(16672, 1000, 1000, $threeDaysAgo);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result);
        // Should find the 3-day-old orders
        $this->assertEquals(50000, $result[0]['total_input_cost']);
        $this->assertEquals(200000, $result[0]['total_output_value']);
    }

    public function test_calculate_all_profitability_with_rig_bonus(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200],
            ],
        ]);

        $this->createSellOrder(16634, 500);
        $this->createSellOrder(16672, 1000);

        // T1 rig lowsec = 2.0% ME bonus
        $result = $this->service->calculateAllProfitability(runs: 1, rigBonus: 2.0);

        $this->assertCount(1, $result);
        // 100 * 1 * 0.98 = 98 → ceil = 98, input cost: 98 * 500 = 49,000
        $this->assertEquals(49000, $result[0]['total_input_cost']);
    }

    public function test_calculate_all_profitability_with_job_costs(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200],
            ],
        ]);

        $this->createSellOrder(16634, 1000);
        $this->createSellOrder(16672, 2000);

        $result = $this->service->calculateAllProfitability(
            runs: 1,
            rigBonus: 0.0,
            facilityTaxRate: 10.0,
            systemCostIndex: 5.0
        );

        $this->assertCount(1, $result);
        $this->assertEquals(100000, $result[0]['total_input_cost']);
        $this->assertEquals(5000, $result[0]['job_fee']);
        $this->assertEquals(500, $result[0]['facility_tax']);
        $this->assertEquals(5500, $result[0]['total_job_cost']);
        // Profit: 400,000 output - 100,000 input - 5,500 job cost - 16,000 SCC (4% of output)
        $this->assertEquals(16000, $result[0]['scc_fee']);
        $this->assertEquals(278500, $result[0]['profit']);
    }

    public function test_calculate_all_profitability_sorted_by_profit_desc(): void
    {
        EveType::create(['type_id' => 1, 'name' => 'Formula A', 'published' => true]);
        EveType::create(['type_id' => 2, 'name' => 'Formula B', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 100, 'name' => 'Mat A', 'quantity' => 10]],
            'products' => [['type_id' => 200, 'name' => 'Product A', 'quantity' => 1]],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 2,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 101, 'name' => 'Mat B', 'quantity' => 10]],
            'products' => [['type_id' => 201, 'name' => 'Product B', 'quantity' => 1]],
        ]);

        $this->createSellOrder(100, 100);
        $this->createSellOrder(101, 100);
        $this->createSellOrder(200, 500);
        $this->createSellOrder(201, 5000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(2, $result);
        $this->assertEquals('Product B', $result[0]['product_name']);
        $this->assertEquals('Product A', $result[1]['product_name']);
    }

    public function test_calculate_all_profitability_falls_back_to_market_prices(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200],
            ],
        ]);

        // No sell orders — falls back to market_prices
        MarketPrice::create(['type_id' => 16634, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 500]);
        MarketPrice::create(['type_id' => 16672, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 1000]);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result);
        $this->assertEquals(50000, $result[0]['total_input_cost']);
        $this->assertEquals(200000, $result[0]['total_output_value']);
    }

    public function test_does_not_include_non_reaction_activities(): void
    {
        EveType::create(['type_id' => 1, 'name' => 'Manufacturing BP', 'published' => true]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [['type_id' => 100, 'name' => 'Tritanium', 'quantity' => 1000]],
            'products' => [['type_id' => 200, 'name' => 'Module', 'quantity' => 1]],
        ]);

        $result = $this->service->getAllReactions();

        $this->assertEmpty($result);
    }

    // ── reaction_category classification ────────────────────────────

    public function test_resolve_reaction_category_composite(): void
    {
        $this->assertEquals('composite', ReactionBrowserService::resolveReactionCategory(1888));
    }

    public function test_resolve_reaction_category_biochemical(): void
    {
        $this->assertEquals('biochemical', ReactionBrowserService::resolveReactionCategory(1889));
    }

    public function test_resolve_reaction_category_hybrid(): void
    {
        $this->assertEquals('hybrid', ReactionBrowserService::resolveReactionCategory(4097));
    }

    public function test_resolve_reaction_category_unknown(): void
    {
        $this->assertEquals('other', ReactionBrowserService::resolveReactionCategory(9999));
        $this->assertEquals('other', ReactionBrowserService::resolveReactionCategory(null));
    }

    public function test_get_all_reactions_includes_reaction_category(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'group_id' => ReactionBrowserService::GROUP_COMPOSITE_REACTIONS,
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100]],
            'products' => [['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200]],
        ]);

        $result = $this->service->getAllReactions();

        $this->assertCount(1, $result);
        $this->assertEquals('composite', $result[0]['reaction_category']);
    }

    public function test_calculate_all_profitability_includes_reaction_category(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'group_id' => ReactionBrowserService::GROUP_COMPOSITE_REACTIONS,
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100]],
            'products' => [['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200]],
        ]);

        $this->createSellOrder(16634, 500);
        $this->createSellOrder(16672, 1000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result);
        $this->assertEquals('composite', $result[0]['reaction_category']);
    }

    // ── reaction_complexity classification ──────────────────────────

    public function test_resolve_reaction_complexity_intermediate_when_no_inputs_are_reaction_products(): void
    {
        // Materials are raw moon goo, not products of any reaction
        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ['type_id' => 16635, 'name' => 'Evaporite Deposits', 'quantity' => 100],
        ];
        // Only type 16672 is a reaction product
        $reactionProductTypeIds = [16672 => true];

        $this->assertEquals('intermediate', ReactionBrowserService::resolveReactionComplexity($materials, $reactionProductTypeIds));
    }

    public function test_resolve_reaction_complexity_complex_when_input_is_reaction_product(): void
    {
        // One input material (16672) is itself a product of another reaction
        $materials = [
            ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 100],
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
        ];
        $reactionProductTypeIds = [16672 => true];

        $this->assertEquals('complex', ReactionBrowserService::resolveReactionComplexity($materials, $reactionProductTypeIds));
    }

    public function test_resolve_reaction_complexity_intermediate_with_empty_product_set(): void
    {
        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
        ];

        $this->assertEquals('intermediate', ReactionBrowserService::resolveReactionComplexity($materials, []));
    }

    public function test_get_all_reactions_includes_reaction_complexity(): void
    {
        // Create an intermediate reaction (inputs are raw materials, not reaction products)
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'group_id' => ReactionBrowserService::GROUP_COMPOSITE_REACTIONS,
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100]],
            'products' => [['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200]],
        ]);

        $result = $this->service->getAllReactions();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('reaction_complexity', $result[0]);
        $this->assertEquals('intermediate', $result[0]['reaction_complexity']);
    }

    public function test_get_all_reactions_marks_complex_when_input_is_another_reaction_product(): void
    {
        // Intermediate reaction: raw goo → Fernite Carbide
        EveType::create(['type_id' => 1, 'name' => 'Fernite Carbide Reaction Formula', 'published' => true]);
        BlueprintActivity::create([
            'blueprint_type_id' => 1,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100]],
            'products' => [['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200]],
        ]);

        // Complex reaction: Fernite Carbide (reaction product) → Ferrogel
        EveType::create(['type_id' => 2, 'name' => 'Ferrogel Reaction Formula', 'published' => true]);
        BlueprintActivity::create([
            'blueprint_type_id' => 2,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 100]],
            'products' => [['type_id' => 16673, 'name' => 'Ferrogel', 'quantity' => 1]],
        ]);

        $result = $this->service->getAllReactions();

        $this->assertCount(2, $result);

        // Find each reaction by name (sorted alphabetically)
        $fernite = collect($result)->firstWhere('formula_name', 'Fernite Carbide Reaction Formula');
        $ferrogel = collect($result)->firstWhere('formula_name', 'Ferrogel Reaction Formula');

        $this->assertEquals('intermediate', $fernite['reaction_complexity']);
        $this->assertEquals('complex', $ferrogel['reaction_complexity']);
    }

    public function test_calculate_all_profitability_includes_reaction_complexity(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100]],
            'products' => [['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200]],
        ]);

        $this->createSellOrder(16634, 500);
        $this->createSellOrder(16672, 1000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('reaction_complexity', $result[0]);
        $this->assertEquals('intermediate', $result[0]['reaction_complexity']);
    }

    public function test_calculate_all_profitability_uses_specified_region(): void {} // ── sell score ──────────────────────────────────────────────────

    public function test_sell_score_returns_zero_with_no_history(): void
    {
        $result = $this->service->calculateSellScore(16672, 200);

        $this->assertEquals(0, $result['score']);
        $this->assertEquals(0, $result['avg_daily_volume']);
        $this->assertEquals(0, $result['avg_order_count']);
        $this->assertEquals(0, $result['active_days']);
        $this->assertEquals(0, $result['total_days']);
    }

    public function test_sell_score_high_volume_active_market(): void
    {
        // 30 days of heavy trading: 1000 volume, 25 orders each day
        for ($i = 0; $i < 30; $i++) {
            MarketHistory::create([
                'type_id' => 16672,
                'region_id' => MarketService::DEFAULT_REGION_ID,
                'date' => now()->subDays($i)->toDateString(),
                'average' => 1000,
                'highest' => 1100,
                'lowest' => 900,
                'volume' => 1000,
                'order_count' => 25,
            ]);
        }

        // totalOutput = 200, avgDailyVolume = 1000 → ratio = 5, volumeScore = min(6,15) = 6
        // activeDays = 30/30 → consistencyScore = 2
        // avgOrderCount = 25 → orderScore = 2
        // total = 10
        $result = $this->service->calculateSellScore(16672, 200);

        $this->assertEquals(10, $result['score']);
        $this->assertEquals(1000, $result['avg_daily_volume']);
        $this->assertEquals(25, $result['avg_order_count']);
        $this->assertEquals(30, $result['active_days']);
        $this->assertEquals(30, $result['total_days']);
    }

    public function test_sell_score_low_volume_sparse_market(): void
    {
        // Only 5 days of history with low volume and few orders
        for ($i = 0; $i < 5; $i++) {
            MarketHistory::create([
                'type_id' => 16672,
                'region_id' => MarketService::DEFAULT_REGION_ID,
                'date' => now()->subDays($i)->toDateString(),
                'average' => 500,
                'highest' => 600,
                'lowest' => 400,
                'volume' => 10,
                'order_count' => 2,
            ]);
        }

        // totalOutput = 200, avgDailyVolume = 10 → ratio = 0.05, volumeScore = round(0.15) = 0
        // activeDays = 5/5 → consistencyScore = 2
        // avgOrderCount = 2 → orderScore = 0
        // total = 2
        $result = $this->service->calculateSellScore(16672, 200);

        $this->assertEquals(2, $result['score']);
        $this->assertEquals(10, $result['avg_daily_volume']);
        $this->assertEquals(2, $result['avg_order_count']);
        $this->assertEquals(5, $result['active_days']);
        $this->assertEquals(5, $result['total_days']);
    }

    public function test_sell_score_included_in_profitability_results(): void
    {
        EveType::create([
            'type_id' => 46166,
            'name' => 'Fernite Carbide Reaction Formula',
            'published' => true,
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 46166,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16672, 'name' => 'Fernite Carbide', 'quantity' => 200],
            ],
        ]);

        // Jita prices
        $this->createSellOrderInRegion(16634, 500, MarketService::DEFAULT_REGION_ID);
        $this->createSellOrderInRegion(16672, 1000, MarketService::DEFAULT_REGION_ID);

        // Amarr prices (different)
        $this->createSellOrderInRegion(16634, 800, MarketTradingService::REGION_DOMAIN);
        $this->createSellOrderInRegion(16672, 1500, MarketTradingService::REGION_DOMAIN);

        // Calculate using Amarr region
        $result = $this->service->calculateAllProfitability(
            runs: 1,
            regionId: MarketTradingService::REGION_DOMAIN
        );

        $this->assertCount(1, $result);
        // Input cost: 100 * 800 = 80,000 (Amarr price, not Jita's 500)
        $this->assertEquals(80000, $result[0]['total_input_cost']);
        // Output value: 200 * 1500 = 300,000 (Amarr price, not Jita's 1000)
        $this->assertEquals(300000, $result[0]['total_output_value']);
        // Profit: 300,000 - 80,000 - 12,000 SCC (4% of output) = 208,000
        $this->assertEquals(208000, $result[0]['profit']);
        $this->createSellOrder(16634, 500);
        $this->createSellOrder(16672, 1000);

        $result = $this->service->calculateAllProfitability(runs: 1);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('sell_score', $result[0]);
        $this->assertArrayHasKey('sell_score_avg_daily_volume', $result[0]);
        $this->assertArrayHasKey('sell_score_avg_order_count', $result[0]);
        $this->assertArrayHasKey('sell_score_active_days', $result[0]);
        $this->assertArrayHasKey('sell_score_total_days', $result[0]);
        $this->assertIsInt($result[0]['sell_score']);
        $this->assertGreaterThanOrEqual(0, $result[0]['sell_score']);
        $this->assertLessThanOrEqual(10, $result[0]['sell_score']);
    }

    public function test_sell_score_capped_at_ten(): void
    {
        // Extremely high volume market
        for ($i = 0; $i < 30; $i++) {
            MarketHistory::create([
                'type_id' => 16672,
                'region_id' => MarketService::DEFAULT_REGION_ID,
                'date' => now()->subDays($i)->toDateString(),
                'average' => 1000,
                'highest' => 1100,
                'lowest' => 900,
                'volume' => 100000,
                'order_count' => 500,
            ]);
        }

        $result = $this->service->calculateSellScore(16672, 200);

        $this->assertLessThanOrEqual(10, $result['score']);
    }
}
