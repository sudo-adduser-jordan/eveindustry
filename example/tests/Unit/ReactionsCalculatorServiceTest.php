<?php

namespace Tests\Unit;

use App\Models\MarketOrder;
use App\Models\MarketPrice;
use App\Services\EsiService;
use App\Services\MarketService;
use App\Services\ReactionsCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ReactionsCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReactionsCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $esi = Mockery::mock(EsiService::class);
        $market = new MarketService($esi);
        $this->service = new ReactionsCalculatorService($esi, $market);
    }

    private function createSellOrder(int $typeId, float $price, int $volumeRemain = 1000, ?string $issued = null): void
    {
        static $orderId = 8000000;
        $orderId++;

        MarketOrder::create([
            'order_id' => $orderId,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'type_id' => $typeId,
            'is_buy_order' => false,
            'price' => $price,
            'volume_remain' => $volumeRemain,
            'volume_total' => $volumeRemain,
            'issued' => $issued ?? now()->toDateTimeString(),
        ]);
    }

    // ── calculateMaterials ──────────────────────────────────────────

    public function test_calculate_materials_no_rig_single_run(): void
    {
        $base = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ['type_id' => 16635, 'name' => 'Evaporite Deposits', 'quantity' => 200],
        ];

        $result = $this->service->calculateMaterials($base, runs: 1);

        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]['adjusted_quantity']);
        $this->assertEquals(200, $result[1]['adjusted_quantity']);
        $this->assertEquals(100, $result[0]['base_quantity']);
    }

    public function test_calculate_materials_with_t1_rig(): void
    {
        $base = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
        ];

        // T1 rig = 2.4% reduction: 100 * 1 * 0.976 = 97.6 → ceil = 98
        $result = $this->service->calculateMaterials($base, runs: 1, rigBonus: 2.4);

        $this->assertEquals(98, $result[0]['adjusted_quantity']);
    }

    public function test_calculate_materials_with_t2_rig(): void
    {
        $base = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
        ];

        // T2 rig = 2.88% reduction: 100 * 1 * 0.9712 = 97.12 → ceil = 98
        $result = $this->service->calculateMaterials($base, runs: 1, rigBonus: 2.88);

        $this->assertEquals(98, $result[0]['adjusted_quantity']);
    }

    public function test_calculate_materials_multiple_runs(): void
    {
        $base = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
        ];

        // No rig, 10 runs: 100 * 10 * 1.0 = 1000
        $result = $this->service->calculateMaterials($base, runs: 10);

        $this->assertEquals(1000, $result[0]['adjusted_quantity']);
        $this->assertEquals(100, $result[0]['quantity_per_run']);
    }

    public function test_calculate_materials_minimum_is_runs(): void
    {
        $base = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 1],
        ];

        // T2 rig: 1 * 5 * 0.9712 = 4.856 → ceil = 5, max(5, 5) = 5
        $result = $this->service->calculateMaterials($base, runs: 5, rigBonus: 2.88);

        $this->assertGreaterThanOrEqual(5, $result[0]['adjusted_quantity']);
    }

    // ── calculateProductionTime ─────────────────────────────────────

    public function test_calculate_production_time_no_bonuses(): void
    {
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            runs: 1
        );

        $this->assertEquals(3600, $result['base_time_seconds']);
        $this->assertEquals(3600, $result['time_per_run_seconds']);
        $this->assertEquals(3600, $result['total_time_seconds']);
    }

    public function test_calculate_production_time_with_reactions_skill(): void
    {
        // Reactions 5 = 20% reduction: 3600 * 0.80 = 2880
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            runs: 1,
            reactionsSkill: 5
        );

        $this->assertEquals(2880, $result['time_per_run_seconds']);
    }

    public function test_calculate_production_time_with_structure_bonus(): void
    {
        // 3% structure: 3600 * 0.97 = 3492
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            runs: 1,
            structureTeBonus: 3.0
        );

        $this->assertEquals(3492, $result['time_per_run_seconds']);
    }

    public function test_calculate_production_time_with_all_bonuses(): void
    {
        // Reactions5(0.80) * Structure3%(0.97)
        // 3600 * 0.80 * 0.97 = 3600 * 0.776 = 2793.6 → round = 2794
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            runs: 1,
            reactionsSkill: 5,
            structureTeBonus: 3.0
        );

        $this->assertEquals(2794, $result['time_per_run_seconds']);
    }

    public function test_calculate_production_time_multiple_runs(): void
    {
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            runs: 5
        );

        $this->assertEquals(3600, $result['time_per_run_seconds']);
        $this->assertEquals(18000, $result['total_time_seconds']);
    }

    public function test_calculate_production_time_formatted_output(): void
    {
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            runs: 1
        );

        $this->assertEquals('1h 0m 0s', $result['time_per_run_formatted']);
        $this->assertEquals('1h 0m 0s', $result['total_time_formatted']);
    }

    // ── formatDuration ──────────────────────────────────────────────

    public function test_format_duration_seconds_only(): void
    {
        $this->assertEquals('45s', $this->service->formatDuration(45));
    }

    public function test_format_duration_minutes_and_seconds(): void
    {
        $this->assertEquals('2m 5s', $this->service->formatDuration(125));
    }

    public function test_format_duration_hours(): void
    {
        $this->assertEquals('1h 1m 1s', $this->service->formatDuration(3661));
    }

    public function test_format_duration_days(): void
    {
        $this->assertEquals('1d 1h 1m 1s', $this->service->formatDuration(90061));
    }

    public function test_format_duration_zero(): void
    {
        $this->assertEquals('0s', $this->service->formatDuration(0));
    }

    // ── resolveRigMeBonus ───────────────────────────────────────────

    public function test_resolve_rig_me_bonus_none(): void
    {
        $this->assertEquals(0.0, ReactionsCalculatorService::resolveRigMeBonus('none', 'lowsec'));
        $this->assertEquals(0.0, ReactionsCalculatorService::resolveRigMeBonus('none', 'nullsec'));
    }

    public function test_resolve_rig_me_bonus_t1_lowsec(): void
    {
        // T1 in lowsec: 2.0% × 1.0 = 2.0%
        $this->assertEquals(2.0, ReactionsCalculatorService::resolveRigMeBonus('t1', 'lowsec'));
    }

    public function test_resolve_rig_me_bonus_t1_nullsec(): void
    {
        // T1 in nullsec/WH: 2.0% × 1.1 = 2.2%
        $this->assertEqualsWithDelta(2.2, ReactionsCalculatorService::resolveRigMeBonus('t1', 'nullsec'), 0.001);
    }

    public function test_resolve_rig_me_bonus_t2_lowsec(): void
    {
        // T2 in lowsec: 2.4% × 1.0 = 2.4%
        $this->assertEquals(2.4, ReactionsCalculatorService::resolveRigMeBonus('t2', 'lowsec'));
    }

    public function test_resolve_rig_me_bonus_t2_nullsec(): void
    {
        // T2 in nullsec/WH: 2.4% × 1.1 = 2.64%
        $this->assertEqualsWithDelta(2.64, ReactionsCalculatorService::resolveRigMeBonus('t2', 'nullsec'), 0.001);
    }

    public function test_resolve_rig_me_bonus_unknown_defaults(): void
    {
        // Unknown values should default safely
        $this->assertEquals(0.0, ReactionsCalculatorService::resolveRigMeBonus('invalid', 'lowsec'));
        $this->assertEquals(0.0, ReactionsCalculatorService::resolveRigMeBonus('none', 'invalid'));
    }

    // ── calculateCostProfit ─────────────────────────────────────────

    public function test_calculate_cost_profit_uses_sell_orders(): void
    {
        $this->createSellOrder(16634, 500);
        $this->createSellOrder(16672, 1000);

        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100, 'adjusted_quantity' => 100, 'base_quantity' => 100, 'quantity_per_run' => 100],
        ];

        $result = $this->service->calculateCostProfit($materials, 16672, runs: 1, portionSize: 200);

        $this->assertEquals(50000, $result['total_material_cost']);
        $this->assertEquals(500, $result['materials'][0]['unit_price']);
        $this->assertEquals(1000, $result['product_sell_price']);
        $this->assertEquals(200000, $result['total_revenue']);
    }

    public function test_calculate_cost_profit_uses_average_sell_five_percent(): void
    {
        // Material: total vol = 1000, 5% = 50 units
        // Sorted: 400@50, 500@50, 800@900
        // 50 units all from first order → avg = 400
        $this->createSellOrder(16634, 400, 50);
        $this->createSellOrder(16634, 500, 50);
        $this->createSellOrder(16634, 800, 900);
        $this->createSellOrder(16672, 1000, 1000);

        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100, 'adjusted_quantity' => 100, 'base_quantity' => 100, 'quantity_per_run' => 100],
        ];

        $result = $this->service->calculateCostProfit($materials, 16672, runs: 1, portionSize: 200);

        // Uses avg sell 5%: 100 * 400 = 40,000
        $this->assertEquals(40000, $result['total_material_cost']);
        $this->assertEquals(400, $result['materials'][0]['unit_price']);
    }

    public function test_calculate_cost_profit_includes_orders_from_all_dates(): void
    {
        // Orders from different days — all should be included in 5% calc
        $threeDaysAgo = now()->subDays(3)->toDateTimeString();
        $this->createSellOrder(16634, 500, 1000, $threeDaysAgo);
        $this->createSellOrder(16672, 1000, 1000, $threeDaysAgo);

        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100, 'adjusted_quantity' => 100, 'base_quantity' => 100, 'quantity_per_run' => 100],
        ];

        $result = $this->service->calculateCostProfit($materials, 16672, runs: 1, portionSize: 200);

        $this->assertEquals(50000, $result['total_material_cost']);
        $this->assertEquals(200000, $result['total_revenue']);
    }

    public function test_calculate_cost_profit_falls_back_to_market_prices(): void
    {
        // No sell orders at all — falls back to market_prices
        MarketPrice::create(['type_id' => 16634, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 500]);
        MarketPrice::create(['type_id' => 16672, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 1000]);

        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100, 'adjusted_quantity' => 100, 'base_quantity' => 100, 'quantity_per_run' => 100],
        ];

        $result = $this->service->calculateCostProfit($materials, 16672, runs: 1, portionSize: 200);

        $this->assertEquals(50000, $result['total_material_cost']);
        $this->assertEquals(200000, $result['total_revenue']);
    }

    public function test_calculate_cost_profit_computes_profit(): void
    {
        $this->createSellOrder(16634, 500);
        $this->createSellOrder(16635, 600);
        $this->createSellOrder(16672, 1000);

        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100, 'adjusted_quantity' => 100, 'base_quantity' => 100, 'quantity_per_run' => 100],
            ['type_id' => 16635, 'name' => 'Evaporite Deposits', 'quantity' => 100, 'adjusted_quantity' => 100, 'base_quantity' => 100, 'quantity_per_run' => 100],
        ];

        $result = $this->service->calculateCostProfit($materials, 16672, runs: 1, portionSize: 200);

        // Input: (100*500) + (100*600) = 110,000
        // Output: 200 * 1000 = 200,000
        // SCC fee: 4% of 200,000 = 8,000
        // Profit: 200,000 - 110,000 - 8,000 = 82,000
        // Margin: profit / input = 82,000 / 110,000 = 74.5%
        $this->assertEquals(110000, $result['total_material_cost']);
        $this->assertEquals(200000, $result['total_revenue']);
        $this->assertEquals(8000, $result['scc_fee']);
        $this->assertEquals(82000, $result['profit']);
        $this->assertEquals(74.5, $result['profit_margin']);
        $this->assertEquals('orders', $result['materials'][0]['price_source']);
        $this->assertEquals('orders', $result['product_price_source']);
        $this->assertTrue($result['profit_positive']);
        $this->assertEquals(200, $result['total_output']);
    }
}
