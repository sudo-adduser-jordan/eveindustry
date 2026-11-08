<?php

namespace Tests\Unit;

use App\Models\EveType;
use App\Services\EsiService;
use App\Services\MarketService;
use App\Services\ReprocessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class ReprocessingServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReprocessingService $service;

    private EsiService $esi;

    private MarketService $market;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esi = Mockery::mock(EsiService::class);
        $this->market = Mockery::mock(MarketService::class);
        $this->service = new ReprocessingService($this->esi, $this->market);
    }

    // ── calculateYield ──────────────────────────────────────────────

    public function test_calculate_yield_no_skills(): void
    {
        $skills = [
            'reprocessing' => 0,
            'reprocessing_efficiency' => 0,
            'scrapmetal_processing' => 0,
        ];

        $yield = $this->service->calculateYield($skills);

        // Base yield is 50%
        $this->assertEqualsWithDelta(0.50, $yield, 0.001);
    }

    public function test_calculate_yield_max_skills(): void
    {
        $skills = [
            'reprocessing' => 5,
            'reprocessing_efficiency' => 5,
            'scrapmetal_processing' => 5,
        ];

        // 0.50 * (1 + 5*0.03) * (1 + 5*0.02) * (1 + 5*0.02)
        // 0.50 * 1.15 * 1.10 * 1.10 = 0.69575
        $yield = $this->service->calculateYield($skills);

        $this->assertEqualsWithDelta(0.69575, $yield, 0.001);
    }

    public function test_calculate_yield_partial_skills(): void
    {
        $skills = [
            'reprocessing' => 3,
            'reprocessing_efficiency' => 2,
            'scrapmetal_processing' => 0,
        ];

        // 0.50 * (1 + 3*0.03) * (1 + 2*0.02) * (1 + 0*0.02)
        // 0.50 * 1.09 * 1.04 * 1.00 = 0.5668
        $yield = $this->service->calculateYield($skills);

        $this->assertEqualsWithDelta(0.5668, $yield, 0.001);
    }

    public function test_calculate_yield_reprocessing_only(): void
    {
        $skills = [
            'reprocessing' => 5,
            'reprocessing_efficiency' => 0,
            'scrapmetal_processing' => 0,
        ];

        // 0.50 * 1.15 * 1.00 * 1.00 = 0.575
        $yield = $this->service->calculateYield($skills);

        $this->assertEqualsWithDelta(0.575, $yield, 0.001);
    }

    public function test_calculate_yield_missing_keys_default_to_zero(): void
    {
        $skills = [];

        $yield = $this->service->calculateYield($skills);

        $this->assertEqualsWithDelta(0.50, $yield, 0.001);
    }

    public function test_calculate_yield_single_level_skills(): void
    {
        $skills = [
            'reprocessing' => 1,
            'reprocessing_efficiency' => 1,
            'scrapmetal_processing' => 1,
        ];

        // 0.50 * (1 + 0.03) * (1 + 0.02) * (1 + 0.02)
        // 0.50 * 1.03 * 1.02 * 1.02 = 0.50 * 1.071012 = 0.535506
        $yield = $this->service->calculateYield($skills);

        $this->assertEqualsWithDelta(0.535506, $yield, 0.001);
    }

    // ── findProfitableReprocessing ──────────────────────────────────

    /**
     * Helper to invoke the protected findProfitableReprocessing method.
     */
    private function callFindProfitable(array $items, float $yield, array $reprocessData): array
    {
        $method = new ReflectionMethod(ReprocessingService::class, 'findProfitableReprocessing');

        return $method->invoke($this->service, $items, $yield, $reprocessData);
    }

    public function test_reprocessing_uses_portion_size_for_ore(): void
    {
        // Veldspar: portionSize=100, yields 415 Tritanium per 100 units
        EveType::create([
            'type_id' => 1230,
            'name' => 'Veldspar',
            'portion_size' => 100,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'portion_size' => 1,
            'published' => true,
        ]);

        $items = [
            ['type_id' => 1230, 'quantity' => 1000, 'is_singleton' => false],
        ];

        $reprocessData = [
            1230 => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 415],
            ],
        ];

        $yield = 0.50;

        // Tritanium sell price makes reprocessing profitable
        $tritPrice = new \stdClass;
        $tritPrice->sell_min = 10.0;
        $tritPrice->average_price = 10.0;

        // Veldspar sell price (low enough to make reprocessing profitable)
        $veldPrice = new \stdClass;
        $veldPrice->sell_min = 1.0;
        $veldPrice->average_price = 1.0;

        $this->market->shouldReceive('getPrices')
            ->once()
            ->andReturn([1230 => $veldPrice, 34 => $tritPrice]);

        $result = $this->callFindProfitable($items, $yield, $reprocessData);

        // batches = floor(1000 / 100) = 10
        // matQty = floor(415 * 0.50) * 10 = 207 * 10 = 2070
        // reprocess_value = 2070 * 10 = 20700
        $this->assertCount(1, $result);
        $this->assertEquals(2070, $result[0]['materials'][0]['quantity']);
        $this->assertEquals(20700, $result[0]['reprocess_value']);
    }

    public function test_reprocessing_portion_size_one_for_modules(): void
    {
        // Module with portionSize=1
        EveType::create([
            'type_id' => 5000,
            'name' => 'Test Module',
            'portion_size' => 1,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'portion_size' => 1,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 35,
            'name' => 'Pyerite',
            'portion_size' => 1,
            'published' => true,
        ]);

        $items = [
            ['type_id' => 5000, 'quantity' => 10, 'is_singleton' => false],
        ];

        $reprocessData = [
            5000 => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
                ['type_id' => 35, 'name' => 'Pyerite', 'quantity' => 50],
            ],
        ];

        $yield = 0.50;

        $tritPrice = new \stdClass;
        $tritPrice->sell_min = 5.0;
        $tritPrice->average_price = 5.0;

        $pyePrice = new \stdClass;
        $pyePrice->sell_min = 10.0;
        $pyePrice->average_price = 10.0;

        $modPrice = new \stdClass;
        $modPrice->sell_min = 1.0;
        $modPrice->average_price = 1.0;

        $this->market->shouldReceive('getPrices')
            ->once()
            ->andReturn([5000 => $modPrice, 34 => $tritPrice, 35 => $pyePrice]);

        $result = $this->callFindProfitable($items, $yield, $reprocessData);

        // portionSize=1, batches = floor(10/1) = 10
        // Tritanium: floor(100 * 0.50) * 10 = 50 * 10 = 500, value = 500 * 5 = 2500
        // Pyerite:   floor(50  * 0.50) * 10 = 25 * 10 = 250, value = 250 * 10 = 2500
        // reprocess_value = 5000, market_value = 10
        $this->assertCount(1, $result);
        $this->assertEquals(500, $result[0]['materials'][0]['quantity']);
        $this->assertEquals(250, $result[0]['materials'][1]['quantity']);
        $this->assertEquals(5000, $result[0]['reprocess_value']);
    }

    public function test_reprocessing_skips_items_below_portion_size(): void
    {
        // Ore with portionSize=100 but only 50 units - cannot reprocess
        EveType::create([
            'type_id' => 1230,
            'name' => 'Veldspar',
            'portion_size' => 100,
            'published' => true,
        ]);

        $items = [
            ['type_id' => 1230, 'quantity' => 50, 'is_singleton' => false],
        ];

        $reprocessData = [
            1230 => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 415],
            ],
        ];

        $this->market->shouldReceive('getPrices')
            ->once()
            ->andReturn([]);

        $result = $this->callFindProfitable($items, 0.50, $reprocessData);

        // quantity (50) < portionSize (100), so batches = 0, item is skipped
        $this->assertCount(0, $result);
    }

    public function test_reprocessing_floor_applied_per_batch_not_total(): void
    {
        // Verify that floor is applied per-batch, not to the total quantity
        EveType::create([
            'type_id' => 1230,
            'name' => 'Veldspar',
            'portion_size' => 100,
            'published' => true,
        ]);
        EveType::create([
            'type_id' => 34,
            'name' => 'Tritanium',
            'portion_size' => 1,
            'published' => true,
        ]);

        $items = [
            ['type_id' => 1230, 'quantity' => 300, 'is_singleton' => false],
        ];

        $reprocessData = [
            1230 => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 333],
            ],
        ];

        $yield = 0.60;

        $tritPrice = new \stdClass;
        $tritPrice->sell_min = 10.0;
        $tritPrice->average_price = 10.0;

        $veldPrice = new \stdClass;
        $veldPrice->sell_min = 1.0;
        $veldPrice->average_price = 1.0;

        $this->market->shouldReceive('getPrices')
            ->once()
            ->andReturn([1230 => $veldPrice, 34 => $tritPrice]);

        $result = $this->callFindProfitable($items, $yield, $reprocessData);

        // batches = floor(300 / 100) = 3
        // matQty = floor(333 * 0.60) * 3 = floor(199.8) * 3 = 199 * 3 = 597
        // NOT floor(333 * 300 * 0.60) = floor(59940) = 59940 (old buggy formula)
        $this->assertCount(1, $result);
        $this->assertEquals(597, $result[0]['materials'][0]['quantity']);
    }

    public function test_reprocessing_skips_items_with_no_market_price(): void
    {
        // Nitrogen Fuel Block has no price; Veldspar is priced. Only Veldspar
        // may be listed, and the unpriced item must not count as pure profit.
        EveType::create(['type_id' => 4051, 'name' => 'Nitrogen Fuel Block', 'portion_size' => 40, 'published' => true]);
        EveType::create(['type_id' => 4247, 'name' => 'Helium Fuel Block', 'portion_size' => 40, 'published' => true]);
        EveType::create(['type_id' => 1230, 'name' => 'Veldspar', 'portion_size' => 100, 'published' => true]);

        $items = [
            ['type_id' => 4051, 'quantity' => 4000, 'is_singleton' => false],
            ['type_id' => 4247, 'quantity' => 4000, 'is_singleton' => false],
            ['type_id' => 1230, 'quantity' => 1000, 'is_singleton' => false],
        ];

        $reprocessData = [
            4051 => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 1000]],
            4247 => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 1000]],
            1230 => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 415]],
        ];

        $tritPrice = (object) ['sell_min' => 10.0, 'average_price' => 10.0];
        $veldPrice = (object) ['sell_min' => 1.0, 'average_price' => 1.0];
        // A price row with zeroes is as unknown as a missing one
        $heliumPrice = (object) ['sell_min' => 0, 'average_price' => 0];

        $this->market->shouldReceive('getPrices')
            ->once()
            ->andReturn([1230 => $veldPrice, 4247 => $heliumPrice, 34 => $tritPrice]);

        $result = $this->callFindProfitable($items, 0.50, $reprocessData);

        $this->assertCount(1, $result);
        $this->assertEquals(1230, $result[0]['type_id']);
    }

    public function test_reprocessing_falls_back_to_average_price_when_no_sell_orders(): void
    {
        EveType::create(['type_id' => 1230, 'name' => 'Veldspar', 'portion_size' => 100, 'published' => true]);

        $items = [
            ['type_id' => 1230, 'quantity' => 1000, 'is_singleton' => false],
        ];

        $reprocessData = [
            1230 => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 415]],
        ];

        $tritPrice = (object) ['sell_min' => 10.0, 'average_price' => 10.0];
        $veldPrice = (object) ['sell_min' => 0, 'average_price' => 2.0];

        $this->market->shouldReceive('getPrices')
            ->once()
            ->andReturn([1230 => $veldPrice, 34 => $tritPrice]);

        $result = $this->callFindProfitable($items, 0.50, $reprocessData);

        // market_value = 1000 * 2 = 2000; reprocess_value = 2070 * 10 = 20700
        $this->assertCount(1, $result);
        $this->assertEquals(2000, $result[0]['market_value']);
        $this->assertEquals(935.0, $result[0]['profit_pct']);
    }
}
