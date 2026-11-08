<?php

namespace Tests\Unit;

use App\Models\MarketPrice;
use App\Services\EsiService;
use App\Services\FactoryPlanetPlannerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class FactoryPlanetPlannerServiceTest extends TestCase
{
    use RefreshDatabase;

    private FactoryPlanetPlannerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FactoryPlanetPlannerService();
    }

    // ── Constants ──────────────────────────────────────────────────

    public function test_tier_labels_exist(): void
    {
        $this->assertArrayHasKey('p1_to_p2', FactoryPlanetPlannerService::TIER_LABELS);
        $this->assertArrayHasKey('p2_to_p3', FactoryPlanetPlannerService::TIER_LABELS);
        $this->assertArrayHasKey('p3_to_p4', FactoryPlanetPlannerService::TIER_LABELS);
        $this->assertArrayHasKey('p1_to_p3', FactoryPlanetPlannerService::TIER_LABELS);
    }

    public function test_cycle_times_are_positive(): void
    {
        $this->assertGreaterThan(0, FactoryPlanetPlannerService::CYCLE_P1_TO_P2);
        $this->assertGreaterThan(0, FactoryPlanetPlannerService::CYCLE_P2_TO_P3);
        $this->assertGreaterThan(0, FactoryPlanetPlannerService::CYCLE_P3_TO_P4);
    }

    public function test_p1_to_p2_cycle_shorter_than_p2_to_p3(): void
    {
        $this->assertLessThan(
            FactoryPlanetPlannerService::CYCLE_P2_TO_P3,
            FactoryPlanetPlannerService::CYCLE_P1_TO_P2
        );
    }

    public function test_factory_slots_constant(): void
    {
        $this->assertGreaterThan(0, FactoryPlanetPlannerService::FACTORY_SLOTS);
    }

    // ── Schematic data integrity ───────────────────────────────────

    public function test_p1_to_p2_schematics_each_have_required_keys(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P1_TO_P2 as $typeId => $s) {
            $this->assertArrayHasKey('name', $s, "Missing 'name' for P2 type {$typeId}");
            $this->assertArrayHasKey('output_qty', $s, "Missing 'output_qty' for P2 type {$typeId}");
            $this->assertArrayHasKey('cycle_time', $s, "Missing 'cycle_time' for P2 type {$typeId}");
            $this->assertArrayHasKey('inputs', $s, "Missing 'inputs' for P2 type {$typeId}");
            $this->assertCount(2, $s['inputs'], "P1→P2 schematic {$typeId} must have exactly 2 inputs");
        }
    }

    public function test_p2_to_p3_schematics_each_have_required_keys(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3 as $typeId => $s) {
            $this->assertArrayHasKey('name', $s);
            $this->assertArrayHasKey('output_qty', $s);
            $this->assertArrayHasKey('cycle_time', $s);
            $this->assertArrayHasKey('inputs', $s);
            $this->assertGreaterThanOrEqual(2, count($s['inputs']), "P2→P3 schematic {$typeId} must have at least 2 inputs");
            $this->assertLessThanOrEqual(3, count($s['inputs']), "P2→P3 schematic {$typeId} must have at most 3 inputs");
        }
    }

    public function test_p3_to_p4_schematics_each_have_required_keys(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P3_TO_P4 as $typeId => $s) {
            $this->assertArrayHasKey('name', $s);
            $this->assertArrayHasKey('output_qty', $s);
            $this->assertArrayHasKey('cycle_time', $s);
            $this->assertArrayHasKey('inputs', $s);
            $this->assertCount(3, $s['inputs'], "P3→P4 schematic {$typeId} must have exactly 3 inputs");
        }
    }

    public function test_p1_to_p2_output_qty_is_five(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P1_TO_P2 as $typeId => $s) {
            $this->assertEquals(5, $s['output_qty'], "P1→P2 schematic {$typeId} should output 5 units");
        }
    }

    public function test_p2_to_p3_output_qty_is_three(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3 as $typeId => $s) {
            $this->assertEquals(3, $s['output_qty'], "P2→P3 schematic {$typeId} should output 3 units");
        }
    }

    public function test_p3_to_p4_output_qty_is_one(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P3_TO_P4 as $typeId => $s) {
            $this->assertEquals(1, $s['output_qty'], "P3→P4 schematic {$typeId} should output 1 unit");
        }
    }

    public function test_p1_to_p2_schematics_input_qty_is_forty(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P1_TO_P2 as $typeId => $s) {
            foreach ($s['inputs'] as [$inputTypeId, $qty]) {
                $this->assertEquals(40, $qty, "P1→P2 schematic {$typeId}: input {$inputTypeId} should need 40 units");
            }
        }
    }

    public function test_p1_to_p2_inputs_are_valid_p1_materials(): void
    {
        $p1Ids = array_keys(FactoryPlanetPlannerService::P1_NAMES);
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P1_TO_P2 as $typeId => $s) {
            foreach ($s['inputs'] as [$inputTypeId]) {
                $this->assertContains($inputTypeId, $p1Ids,
                    "P1→P2 schematic {$typeId}: input {$inputTypeId} is not a known P1 material");
            }
        }
    }

    public function test_p2_to_p3_inputs_are_valid_p2_materials(): void
    {
        $p2Ids = array_keys(FactoryPlanetPlannerService::P2_NAMES);
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3 as $typeId => $s) {
            foreach ($s['inputs'] as [$inputTypeId]) {
                $this->assertContains($inputTypeId, $p2Ids,
                    "P2→P3 schematic {$typeId}: input {$inputTypeId} is not a known P2 material");
            }
        }
    }

    public function test_p3_to_p4_inputs_are_valid_p3_or_p1_materials(): void
    {
        $p3Ids = array_keys(FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3);
        $p1Ids = array_keys(FactoryPlanetPlannerService::P1_NAMES);
        $validIds = array_merge($p3Ids, $p1Ids);
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P3_TO_P4 as $typeId => $s) {
            foreach ($s['inputs'] as [$inputTypeId]) {
                $this->assertContains($inputTypeId, $validIds,
                    "P3→P4 schematic {$typeId}: input {$inputTypeId} is not a known P3 or P1 material");
            }
        }
    }

    public function test_all_eight_p4_products_are_present(): void
    {
        $p4TypeIds = [2867, 2868, 2869, 2870, 2871, 2872, 2875, 2876];
        $schematicIds = array_keys(FactoryPlanetPlannerService::SCHEMATICS_P3_TO_P4);

        foreach ($p4TypeIds as $id) {
            $this->assertContains($id, $schematicIds, "P4 type {$id} missing from P3→P4 schematics");
        }
    }

    // ── getInputTypeIds ────────────────────────────────────────────

    public function test_get_input_type_ids_returns_unique_ids_for_p2_tier(): void
    {
        $ids = $this->service->getInputTypeIds('p2_to_p3');

        $this->assertNotEmpty($ids);
        $this->assertEquals(count(array_unique($ids)), count($ids), 'IDs should be unique');
    }

    public function test_get_input_type_ids_returns_empty_for_invalid_tier(): void
    {
        $ids = $this->service->getInputTypeIds('invalid');

        $this->assertEmpty($ids);
    }

    // ── getOutputTypeIds ───────────────────────────────────────────

    public function test_get_output_type_ids_matches_schematic_keys(): void
    {
        $outputIds = $this->service->getOutputTypeIds('p2_to_p3');

        $this->assertEquals(
            array_keys(FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3),
            $outputIds
        );
    }

    // ── resolveInputName ───────────────────────────────────────────

    public function test_resolve_input_name_for_p1_material(): void
    {
        // Reactive Metals is a P1 material (type_id 2398)
        $name = $this->service->resolveInputName(2398);
        $this->assertEquals('Reactive Metals', $name);
    }

    public function test_resolve_input_name_for_p2_material(): void
    {
        // Coolant is a P2 material (type_id 9832)
        $name = $this->service->resolveInputName(9832);
        $this->assertEquals('Coolant', $name);
    }

    public function test_resolve_input_name_for_p3_material(): void
    {
        // Camera Drones is a P3 material (type_id 2345)
        $name = $this->service->resolveInputName(2345);
        $this->assertEquals('Camera Drones', $name);
    }

    public function test_resolve_input_name_for_unknown_type(): void
    {
        $name = $this->service->resolveInputName(99999);
        $this->assertStringContainsString('99999', $name);
    }

    // ── calculateSchematicProfit ───────────────────────────────────

    public function test_calculate_schematic_profit_with_prices(): void
    {
        // Seed market prices for a P2→P3 schematic (Camera Drones = 2345)
        // Inputs: Silicate Glass (3697) × 10 + Rocket Fuel (9830) × 10
        MarketPrice::create([
            'type_id'       => 3697,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 5000.0,
            'buy_max'       => 4800.0,
            'average_price' => 5000.0,
        ]);
        MarketPrice::create([
            'type_id'       => 9830,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 3000.0,
            'buy_max'       => 2900.0,
            'average_price' => 3000.0,
        ]);
        MarketPrice::create([
            'type_id'       => 2345, // Camera Drones output
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 200000.0,
            'buy_max'       => 190000.0,
            'average_price' => 195000.0,
        ]);

        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertNotNull($result);
        $this->assertEquals(2345, $result['output_type_id']);
        $this->assertEquals('Camera Drones', $result['output_name']);
        $this->assertEquals(3, $result['output_qty']);

        // Input cost: (4800 × 10) + (2900 × 10) = 48000 + 29000 = 77000
        $this->assertEquals(77000.0, $result['total_input_cost']);

        // Sell value: 200000 × 3 = 600000
        $this->assertEquals(600000.0, $result['output_sell_value']);

        // Profit sell: 600000 - 77000 = 523000
        $this->assertEquals(523000.0, $result['profit_per_cycle_sell']);

        $this->assertTrue($result['prices_available']);
    }

    public function test_calculate_schematic_profit_missing_output_price(): void
    {
        // Only seed input prices, no output price
        MarketPrice::create([
            'type_id'       => 3697,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 5000.0,
            'buy_max'       => 4800.0,
            'average_price' => 5000.0,
        ]);
        MarketPrice::create([
            'type_id'       => 9830,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 3000.0,
            'buy_max'       => 2900.0,
            'average_price' => 3000.0,
        ]);

        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertNotNull($result);
        $this->assertFalse($result['prices_available']);
        $this->assertNull($result['output_sell_min']);
        $this->assertEquals(0.0, $result['output_sell_value']);
        $this->assertEquals(0.0 - 77000.0, $result['profit_per_cycle_sell']);
    }

    public function test_calculate_schematic_profit_no_prices_returns_result_with_zero_values(): void
    {
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertNotNull($result);
        $this->assertFalse($result['prices_available']);
        $this->assertEquals(0.0, $result['total_input_cost']);
    }

    public function test_calculate_schematic_profit_cycles_per_day(): void
    {
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345]; // 90 min cycle
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        // 86400 / 5400 = 16 cycles per day
        $this->assertEquals(16.0, $result['cycles_per_day']);
    }

    public function test_p4_schematic_cycles_per_day(): void
    {
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P3_TO_P4[2867]; // 5 hr cycle
        $result = $this->service->calculateSchematicProfit(
            2867,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        // 86400 / 18000 = 4.8 cycles per day
        $this->assertEqualsWithDelta(4.8, $result['cycles_per_day'], 0.01);
    }

    // ── calculateProfitability ─────────────────────────────────────

    public function test_calculate_profitability_returns_all_p2_products(): void
    {
        $results = $this->service->calculateProfitability('p1_to_p2');

        $expectedCount = count(FactoryPlanetPlannerService::SCHEMATICS_P1_TO_P2);
        $this->assertCount($expectedCount, $results);
    }

    public function test_calculate_profitability_returns_all_p3_products(): void
    {
        $results = $this->service->calculateProfitability('p2_to_p3');

        $expectedCount = count(FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3);
        $this->assertCount($expectedCount, $results);
    }

    public function test_calculate_profitability_returns_all_p4_products(): void
    {
        $results = $this->service->calculateProfitability('p3_to_p4');

        $expectedCount = count(FactoryPlanetPlannerService::SCHEMATICS_P3_TO_P4);
        $this->assertCount($expectedCount, $results);
    }

    public function test_calculate_profitability_returns_empty_for_invalid_tier(): void
    {
        $results = $this->service->calculateProfitability('invalid_tier');

        $this->assertEmpty($results);
    }

    public function test_calculate_profitability_sorted_by_daily_profit_descending(): void
    {
        // Seed prices so two products have different profits
        // Camera Drones (2345): inputs 3697, 9830
        MarketPrice::create(['type_id' => 3697, 'region_id' => FactoryPlanetPlannerService::DEFAULT_REGION_ID, 'sell_min' => 1000.0, 'buy_max' => 900.0, 'average_price' => 1000.0]);
        MarketPrice::create(['type_id' => 9830, 'region_id' => FactoryPlanetPlannerService::DEFAULT_REGION_ID, 'sell_min' => 1000.0, 'buy_max' => 900.0, 'average_price' => 1000.0]);
        MarketPrice::create(['type_id' => 2345, 'region_id' => FactoryPlanetPlannerService::DEFAULT_REGION_ID, 'sell_min' => 500000.0, 'buy_max' => 480000.0, 'average_price' => 490000.0]);

        // Condensates (2344): inputs 2317, 9832 — cheaper output
        MarketPrice::create(['type_id' => 9832, 'region_id' => FactoryPlanetPlannerService::DEFAULT_REGION_ID, 'sell_min' => 1000.0, 'buy_max' => 900.0, 'average_price' => 1000.0]);
        MarketPrice::create(['type_id' => 2317, 'region_id' => FactoryPlanetPlannerService::DEFAULT_REGION_ID, 'sell_min' => 1000.0, 'buy_max' => 900.0, 'average_price' => 1000.0]);
        MarketPrice::create(['type_id' => 2344, 'region_id' => FactoryPlanetPlannerService::DEFAULT_REGION_ID, 'sell_min' => 50000.0, 'buy_max' => 48000.0, 'average_price' => 49000.0]);

        $results = $this->service->calculateProfitability('p2_to_p3');

        // Verify sorted order: higher profit first
        for ($i = 0; $i < count($results) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $results[$i + 1]['daily_profit_per_factory'],
                $results[$i]['daily_profit_per_factory'],
                'Results should be sorted by daily profit descending'
            );
        }
    }

    public function test_result_has_all_required_keys(): void
    {
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(2345, $schematic, FactoryPlanetPlannerService::DEFAULT_REGION_ID);

        $requiredKeys = [
            'output_type_id', 'output_name', 'output_qty', 'cycle_time',
            'inputs', 'total_input_cost', 'output_sell_min', 'output_buy_max',
            'output_price_source', 'output_sell_value', 'output_buy_value',
            'profit_per_cycle_sell', 'profit_per_cycle_buy',
            'margin_percent_sell', 'margin_percent_buy',
            'cycles_per_day', 'daily_profit_per_factory', 'daily_profit_buy_per_factory',
            'prices_available',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_input_details_include_price_source(): void
    {
        MarketPrice::create([
            'type_id'       => 3697,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 5000.0,
            'buy_max'       => 4800.0,
            'average_price' => 5000.0,
        ]);
        MarketPrice::create([
            'type_id'       => 9830,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 3000.0,
            'buy_max'       => 2900.0,
            'average_price' => 3000.0,
        ]);
        MarketPrice::create([
            'type_id'       => 2345,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 200000.0,
            'buy_max'       => 190000.0,
            'average_price' => 195000.0,
        ]);

        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(2345, $schematic, FactoryPlanetPlannerService::DEFAULT_REGION_ID);

        foreach ($result['inputs'] as $input) {
            $this->assertArrayHasKey('price_source', $input);
            $this->assertEquals('buy_max', $input['price_source']);
        }
        $this->assertEquals('sell_min', $result['output_price_source']);
    }

    // ── ESI average price fallback ─────────────────────────────────

    public function test_esi_average_price_used_when_db_price_missing_for_input(): void
    {
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getMarketPrices')->once()->andReturn([
            ['type_id' => 3697, 'average_price' => 8000.0, 'adjusted_price' => 8100.0],
            ['type_id' => 9830, 'average_price' => 4000.0, 'adjusted_price' => 4100.0],
            ['type_id' => 2345, 'average_price' => 250000.0, 'adjusted_price' => 255000.0],
        ]);

        $service = new FactoryPlanetPlannerService($esi);

        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $service->calculateProfitability('p2_to_p3');

        // Find the Camera Drones result
        $cameraDrones = null;
        foreach ($result as $r) {
            if ($r['output_type_id'] === 2345) {
                $cameraDrones = $r;
                break;
            }
        }

        $this->assertNotNull($cameraDrones);

        // With ESI prices: input cost = (8000 × 10) + (4000 × 10) = 80000 + 40000 = 120000
        $this->assertEquals(120000.0, $cameraDrones['total_input_cost']);

        // Output sell value: 250000 × 3 = 750000
        $this->assertEquals(750000.0, $cameraDrones['output_sell_value']);

        // Profit: 750000 - 120000 = 630000
        $this->assertEquals(630000.0, $cameraDrones['profit_per_cycle_sell']);

        $this->assertTrue($cameraDrones['prices_available']);
    }

    public function test_esi_average_price_source_label_set_on_inputs(): void
    {
        // Pass ESI prices directly to calculateSchematicProfit (bypassing loadEsiAveragePrices)
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            [3697 => 8000.0, 9830 => 4000.0, 2345 => 250000.0]
        );

        foreach ($result['inputs'] as $input) {
            $this->assertEquals('esi_average', $input['price_source']);
            $this->assertTrue($input['has_price']);
        }
        $this->assertEquals('esi_average', $result['output_price_source']);
    }

    public function test_esi_average_price_used_when_db_has_no_sell_min(): void
    {
        // DB has an entry but no sell_min; also no average_price — only ESI fallback
        MarketPrice::create([
            'type_id'       => 3697,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => null,
            'buy_max'       => null,
            'average_price' => null,
        ]);
        MarketPrice::create([
            'type_id'       => 9830,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => null,
            'buy_max'       => null,
            'average_price' => null,
        ]);

        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            [3697 => 7000.0, 9830 => 3500.0, 2345 => 220000.0]
        );

        // Input cost: (7000 × 10) + (3500 × 10) = 70000 + 35000 = 105000
        $this->assertEquals(105000.0, $result['total_input_cost']);
        $this->assertEquals('esi_average', $result['inputs'][0]['price_source']);
        $this->assertEquals('esi_average', $result['inputs'][1]['price_source']);
        $this->assertEquals('esi_average', $result['output_price_source']);
        $this->assertTrue($result['prices_available']);
    }

    public function test_db_price_takes_precedence_over_esi_average(): void
    {
        // DB has a valid sell_min — ESI should NOT be used
        MarketPrice::create([
            'type_id'       => 3697,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 5000.0,
            'buy_max'       => 4800.0,
            'average_price' => 5000.0,
        ]);
        MarketPrice::create([
            'type_id'       => 9830,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 3000.0,
            'buy_max'       => 2900.0,
            'average_price' => 3000.0,
        ]);
        MarketPrice::create([
            'type_id'       => 2345,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 200000.0,
            'buy_max'       => 190000.0,
            'average_price' => 195000.0,
        ]);

        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];

        // Pass ESI prices with different values; DB values should win
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            [3697 => 99999.0, 9830 => 99999.0, 2345 => 99999.0]
        );

        // Input cost should use DB buy_max: (4800 × 10) + (2900 × 10) = 77000
        $this->assertEquals(77000.0, $result['total_input_cost']);
        $this->assertEquals('buy_max', $result['inputs'][0]['price_source']);
        $this->assertEquals('sell_min', $result['output_price_source']);
    }

    public function test_no_price_when_both_db_and_esi_missing(): void
    {
        // Neither DB nor ESI has data for this type
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            [] // empty ESI prices
        );

        $this->assertFalse($result['prices_available']);
        $this->assertEquals(0.0, $result['total_input_cost']);
        foreach ($result['inputs'] as $input) {
            $this->assertNull($input['price_source']);
            $this->assertFalse($input['has_price']);
        }
    }

    public function test_esi_service_null_returns_empty_prices(): void
    {
        // With null EsiService, loadEsiAveragePrices returns [] and prices remain unavailable
        $service = new FactoryPlanetPlannerService(null);

        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertFalse($result['prices_available']);
        $this->assertEquals(0.0, $result['total_input_cost']);
    }

    public function test_esi_getMarketPrices_exception_falls_through_gracefully(): void
    {
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getMarketPrices')->once()->andThrow(new \RuntimeException('ESI down'));

        $service = new FactoryPlanetPlannerService($esi);

        // Should not throw; ESI prices are empty and prices_available = false
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $service->calculateProfitability('p2_to_p3');

        $this->assertNotEmpty($result);
        // All results should have prices_available = false (no DB data either)
        foreach ($result as $r) {
            $this->assertFalse($r['prices_available']);
        }
    }

    public function test_esi_average_price_used_for_output_when_db_missing(): void
    {
        // DB has input prices but no output price; ESI provides the output average
        MarketPrice::create([
            'type_id'       => 3697,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 5000.0,
            'buy_max'       => 4800.0,
            'average_price' => 5000.0,
        ]);
        MarketPrice::create([
            'type_id'       => 9830,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 3000.0,
            'buy_max'       => 2900.0,
            'average_price' => 3000.0,
        ]);
        // No DB entry for type 2345 (Camera Drones output)

        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[2345];
        $result = $this->service->calculateSchematicProfit(
            2345,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            [2345 => 230000.0] // ESI average for output only
        );

        // Inputs use DB buy_max; output uses ESI average
        $this->assertEquals(77000.0, $result['total_input_cost']);
        $this->assertEquals(230000.0, $result['output_sell_min']);
        $this->assertEquals('esi_average', $result['output_price_source']);
        // Output sell value: 230000 × 3 = 690000
        $this->assertEquals(690000.0, $result['output_sell_value']);
        $this->assertTrue($result['prices_available']);
    }

    // ── P1→P3 single-planet plan ───────────────────────────────────

    public function test_p1_to_p3_tier_label_exists(): void
    {
        $this->assertArrayHasKey('p1_to_p3', FactoryPlanetPlannerService::TIER_LABELS);
    }

    public function test_p1_to_p3_factory_split_constants_sum_to_factory_slots(): void
    {
        // 2-input variant: P3 + P2A + P2B = 18
        $total2 = FactoryPlanetPlannerService::P1_TO_P3_P3_FACTORIES
            + FactoryPlanetPlannerService::P1_TO_P3_P2A_FACTORIES
            + FactoryPlanetPlannerService::P1_TO_P3_P2B_FACTORIES;
        $this->assertEquals(FactoryPlanetPlannerService::FACTORY_SLOTS, $total2);

        // 3-input variant: P3 + 3×P2 = 18
        $total3 = FactoryPlanetPlannerService::P1_TO_P3_3IN_P3_FACTORIES
            + 3 * FactoryPlanetPlannerService::P1_TO_P3_3IN_P2_FACTORIES;
        $this->assertEquals(FactoryPlanetPlannerService::FACTORY_SLOTS, $total3);
    }

    public function test_calculate_p1_to_p3_profitability_returns_all_p3_products(): void
    {
        $results = $this->service->calculateP1toP3Profitability();

        $this->assertCount(count(FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3), $results);
    }

    public function test_calculate_profitability_delegates_to_p1_to_p3_for_that_tier(): void
    {
        $results = $this->service->calculateProfitability('p1_to_p3');

        $this->assertCount(count(FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3), $results);
    }

    public function test_p1_to_p3_plan_has_required_keys(): void
    {
        $p3TypeId  = 2345; // Camera Drones
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertNotNull($result);

        $requiredKeys = [
            'output_type_id', 'output_name', 'output_qty_per_day',
            'factory_slots', 'factory_split', 'p2_inputs', 'p1_inputs',
            'total_daily_p1_cost', 'p3_sell_min', 'p3_buy_max', 'p3_price_source',
            'daily_p3_sell_value', 'daily_p3_buy_value',
            'daily_profit_sell', 'daily_profit_buy',
            'margin_percent_sell',
            'daily_import_value', 'daily_export_value',
            'prices_available',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_p1_to_p3_factory_split_for_camera_drones(): void
    {
        $p3TypeId  = 2345; // Camera Drones (2-input: Silicate Glass + Rocket Fuel)
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $split = $result['factory_split'];
        $this->assertEquals(6,  $split['p3_factories']);
        $this->assertEquals(18, $split['total']);

        // p2_inputs[0] and [1] each have 6 factories
        $this->assertEquals(6, $result['p2_inputs'][0]['factories']);
        $this->assertEquals(6, $result['p2_inputs'][1]['factories']);
    }

    public function test_p1_to_p3_effective_daily_output_for_camera_drones(): void
    {
        // Camera Drones (2-input): 6+6+6 factory split, qty=10
        // P2A supply: 6×5×24 = 720/day, max P3 cycles = 720/10 = 72
        // P2B supply: 6×5×24 = 720/day, max P3 cycles = 720/10 = 72
        // Factory capacity: 6×16 = 96
        // Effective P3 cycles = min(72, 72, 96) = 72 → output = 72×3 = 216
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertEquals(216, $result['output_qty_per_day']);
    }

    public function test_p1_to_p3_p2_inputs_have_correct_structure(): void
    {
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertCount(2, $result['p2_inputs']);

        // Camera Drones uses Silicate Glass (3697) and Rocket Fuel (9830)
        $p2TypeIds = array_column($result['p2_inputs'], 'type_id');
        $this->assertContains(3697, $p2TypeIds); // Silicate Glass
        $this->assertContains(9830, $p2TypeIds); // Rocket Fuel

        foreach ($result['p2_inputs'] as $p2) {
            $this->assertArrayHasKey('type_id', $p2);
            $this->assertArrayHasKey('name', $p2);
            $this->assertArrayHasKey('factories', $p2);
            $this->assertArrayHasKey('daily_produced', $p2);
            $this->assertArrayHasKey('daily_consumed', $p2);
            // Consumed must be <= produced (no P2 deficit at effective rate)
            $this->assertLessThanOrEqual($p2['daily_produced'], $p2['daily_consumed']);
        }
    }

    public function test_p1_to_p3_p1_inputs_are_all_p1_materials(): void
    {
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertNotEmpty($result['p1_inputs']);

        $p1Ids = array_keys(FactoryPlanetPlannerService::P1_NAMES);
        foreach ($result['p1_inputs'] as $p1) {
            $this->assertContains($p1['type_id'], $p1Ids,
                "P1 input type {$p1['type_id']} is not a known P1 material");
            $this->assertGreaterThan(0, $p1['daily_qty']);
        }
    }

    public function test_p1_to_p3_daily_p1_qty_totals_for_camera_drones(): void
    {
        // Camera Drones: 2-input (Silicate Glass + Rocket Fuel), qty=10 each
        // Factory split: 6 P3 + 6 P2A + 6 P2B = 18
        // Effective P3 cycles = 72 (P2 bottleneck: 6×5×24/10 = 72)
        // P2 consumed per group = 72×10 = 720 → P2 cycles needed = 720/5 = 144
        // Each P2 type uses 2 P1 inputs at 40 each → 144×40 = 5760 per P1 type, 4 P1 types
        // Total = 5760 × 4 = 23040
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $totalP1PerDay = array_sum(array_column($result['p1_inputs'], 'daily_qty'));
        $this->assertEquals(23040.0, $totalP1PerDay);
    }

    public function test_p1_to_p3_cost_and_profit_with_prices(): void
    {
        // Camera Drones P1 inputs (via Silicate Glass + Rocket Fuel):
        // Silicate Glass → Oxidizing Compound (2392) + Silicon (9828)
        // Rocket Fuel → Plasmoids (2389) + Electrolytes (2390)
        $p1Prices = [
            2392 => 100.0,  // Oxidizing Compound
            9828 => 200.0,  // Silicon
            2389 => 150.0,  // Plasmoids
            2390 => 120.0,  // Electrolytes
        ];
        foreach ($p1Prices as $typeId => $price) {
            MarketPrice::create([
                'type_id'       => $typeId,
                'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
                'sell_min'      => $price,
                'buy_max'       => $price * 0.9,
                'average_price' => $price,
            ]);
        }
        // P3 output price
        MarketPrice::create([
            'type_id'       => 2345,
            'region_id'     => FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            'sell_min'      => 50000.0,
            'buy_max'       => 48000.0,
            'average_price' => 49000.0,
        ]);

        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        // Daily qty per P1 type = 5760
        // Daily cost = 5760*(100*0.9) + 5760*(200*0.9) + 5760*(150*0.9) + 5760*(120*0.9)
        //            = 5760 * 0.9 * (100+200+150+120) = 5760 * 0.9 * 570 = 2,954,880
        $expectedCost = 5760.0 * 0.9 * (100.0 + 200.0 + 150.0 + 120.0);
        $this->assertEqualsWithDelta($expectedCost, $result['total_daily_p1_cost'], 0.01);

        // Daily P3 revenue = 216 * 50000 = 10,800,000
        $this->assertEqualsWithDelta(216 * 50000.0, $result['daily_p3_sell_value'], 0.01);

        // Daily profit (sell)
        $expectedProfit = 216 * 50000.0 - $expectedCost;
        $this->assertEqualsWithDelta($expectedProfit, $result['daily_profit_sell'], 0.01);

        $this->assertTrue($result['prices_available']);
    }

    public function test_p1_to_p3_daily_import_and_export_values_are_set(): void
    {
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        // Import value = P1 daily cost, export value = P3 daily sell value
        $this->assertEquals($result['total_daily_p1_cost'], $result['daily_import_value']);
        $this->assertEquals($result['daily_p3_sell_value'], $result['daily_export_value']);
    }

    public function test_p1_to_p3_profitability_sorted_by_daily_profit_descending(): void
    {
        $results = $this->service->calculateP1toP3Profitability();

        for ($i = 0; $i < count($results) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $results[$i + 1]['daily_profit_sell'],
                $results[$i]['daily_profit_sell'],
                'P1→P3 results should be sorted by daily profit descending'
            );
        }
    }

    public function test_p1_to_p3_missing_prices_sets_prices_available_false(): void
    {
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        // No prices seeded → prices_available should be false
        $this->assertFalse($result['prices_available']);
        $this->assertEquals(0.0, $result['total_daily_p1_cost']);
    }

    public function test_p1_to_p3_esi_fallback_prices_are_used(): void
    {
        // Camera Drones P1 inputs: Oxidizing Compound (2392), Silicon (9828),
        // Plasmoids (2389), Electrolytes (2390); P3 output: 2345
        $esiPrices = [
            2392 => 80.0,
            9828 => 160.0,
            2389 => 120.0,
            2390 => 100.0,
            2345 => 40000.0,
        ];

        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP1toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID,
            $esiPrices
        );

        $expectedCost = 5760.0 * (80.0 + 160.0 + 120.0 + 100.0);
        $this->assertEqualsWithDelta($expectedCost, $result['total_daily_p1_cost'], 0.01);
        $this->assertTrue($result['prices_available']);

        // All P1 inputs should report esi_average as price source
        foreach ($result['p1_inputs'] as $p1) {
            $this->assertEquals('esi_average', $p1['price_source']);
        }
    }

    // ── P0→P3 single-planet plan ───────────────────────────────────

    public function test_p0_to_p3_tier_label_exists(): void
    {
        $this->assertArrayHasKey('p0_to_p3', FactoryPlanetPlannerService::TIER_LABELS);
    }

    public function test_p0_to_p3_factory_split_constants_sum_to_factory_slots(): void
    {
        $total = FactoryPlanetPlannerService::P0_TO_P3_P3_FACTORIES
            + FactoryPlanetPlannerService::P0_TO_P3_P2A_FACTORIES
            + FactoryPlanetPlannerService::P0_TO_P3_P2B_FACTORIES
            + FactoryPlanetPlannerService::P0_TO_P3_BIF_TOTAL;

        $this->assertEquals(FactoryPlanetPlannerService::FACTORY_SLOTS, $total);
    }

    public function test_p0_to_p1_schematics_each_have_required_keys(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P0_TO_P1 as $typeId => $s) {
            $this->assertArrayHasKey('name', $s, "Missing 'name' for P1 type {$typeId}");
            $this->assertArrayHasKey('output_qty', $s, "Missing 'output_qty' for P1 type {$typeId}");
            $this->assertArrayHasKey('cycle_time', $s, "Missing 'cycle_time' for P1 type {$typeId}");
            $this->assertArrayHasKey('inputs', $s, "Missing 'inputs' for P1 type {$typeId}");
            $this->assertCount(1, $s['inputs'], "P0→P1 schematic {$typeId} must have exactly 1 input");
        }
    }

    public function test_p0_to_p1_output_qty_is_twenty(): void
    {
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P0_TO_P1 as $typeId => $s) {
            $this->assertEquals(20, $s['output_qty'], "P0→P1 schematic {$typeId} should output 20 units");
        }
    }

    public function test_p0_to_p1_inputs_are_valid_p0_materials(): void
    {
        $p0Ids = array_keys(FactoryPlanetPlannerService::P0_NAMES);
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P0_TO_P1 as $typeId => $s) {
            foreach ($s['inputs'] as [$inputTypeId]) {
                $this->assertContains($inputTypeId, $p0Ids,
                    "P0→P1 schematic {$typeId}: input {$inputTypeId} is not a known P0 material");
            }
        }
    }

    public function test_p0_to_p1_outputs_are_valid_p1_materials(): void
    {
        $p1Ids = array_keys(FactoryPlanetPlannerService::P1_NAMES);
        foreach (FactoryPlanetPlannerService::SCHEMATICS_P0_TO_P1 as $typeId => $s) {
            $this->assertContains($typeId, $p1Ids,
                "P0→P1 schematic output {$typeId} is not a known P1 material");
        }
    }

    public function test_all_fifteen_p0_materials_are_present(): void
    {
        $this->assertCount(15, FactoryPlanetPlannerService::P0_NAMES);
        $this->assertCount(15, FactoryPlanetPlannerService::SCHEMATICS_P0_TO_P1);
    }

    public function test_calculate_p0_to_p3_profitability_returns_all_p3_products(): void
    {
        $results = $this->service->calculateP0toP3Profitability();

        $this->assertCount(count(FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3), $results);
    }

    public function test_calculate_profitability_delegates_to_p0_to_p3_for_that_tier(): void
    {
        $results = $this->service->calculateProfitability('p0_to_p3');

        $this->assertCount(count(FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3), $results);
    }

    public function test_p0_to_p3_plan_has_required_keys(): void
    {
        $p3TypeId  = 2345; // Camera Drones
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP0toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertNotNull($result);

        $requiredKeys = [
            'output_type_id', 'output_name', 'output_qty_per_day',
            'factory_slots', 'factory_split', 'p2_inputs', 'p1_intermediates',
            'p0_inputs', 'total_daily_p0_cost',
            'p3_sell_min', 'p3_buy_max', 'p3_price_source',
            'daily_p3_sell_value', 'daily_p3_buy_value',
            'daily_profit_sell', 'daily_profit_buy',
            'margin_percent_sell',
            'daily_import_value', 'daily_export_value',
            'prices_available',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_p0_to_p3_factory_split_has_bif_allocation(): void
    {
        $p3TypeId  = 2345; // Camera Drones (2-input: Silicate Glass + Rocket Fuel)
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP0toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $split = $result['factory_split'];
        $this->assertEquals(1,  $split['p3_factories']);
        $this->assertEquals(11, $split['bif_total']);
        $this->assertEquals(18, $split['total']);

        // BIF allocation sums to bif_total
        $this->assertEquals(11, array_sum($split['bif_allocation']));

        // p2_inputs each have the correct factory count for 2-input P3
        $this->assertCount(2, $result['p2_inputs']);
        foreach ($result['p2_inputs'] as $p2) {
            $this->assertEquals(FactoryPlanetPlannerService::P0_TO_P3_P2A_FACTORIES, $p2['factories']);
        }
    }

    public function test_p0_to_p3_p0_inputs_are_all_p0_materials(): void
    {
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP0toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertNotEmpty($result['p0_inputs']);

        $p0Ids = array_keys(FactoryPlanetPlannerService::P0_NAMES);
        foreach ($result['p0_inputs'] as $p0) {
            $this->assertContains($p0['type_id'], $p0Ids,
                "P0 input type {$p0['type_id']} is not a known P0 material");
            $this->assertGreaterThan(0, $p0['daily_qty']);
        }
    }

    public function test_p0_to_p3_effective_daily_output_is_positive(): void
    {
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP0toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertGreaterThan(0, $result['output_qty_per_day']);
        // Should not exceed P3 factory capacity: 1 HTPP × 16 cycles × 3 = 48
        $this->assertLessThanOrEqual(48, $result['output_qty_per_day']);
    }

    public function test_p0_to_p3_missing_prices_sets_prices_available_false(): void
    {
        $p3TypeId  = 2345;
        $schematic = FactoryPlanetPlannerService::SCHEMATICS_P2_TO_P3[$p3TypeId];

        $result = $this->service->calculateP0toP3Plan(
            $p3TypeId,
            $schematic,
            FactoryPlanetPlannerService::DEFAULT_REGION_ID
        );

        $this->assertFalse($result['prices_available']);
        $this->assertEquals(0.0, $result['total_daily_p0_cost']);
    }

    public function test_p0_to_p3_profitability_sorted_by_daily_profit_descending(): void
    {
        $results = $this->service->calculateP0toP3Profitability();

        for ($i = 0; $i < count($results) - 1; $i++) {
            $this->assertGreaterThanOrEqual(
                $results[$i + 1]['daily_profit_sell'],
                $results[$i]['daily_profit_sell'],
                'P0→P3 results should be sorted by daily profit descending'
            );
        }
    }

    public function test_resolve_input_name_for_p0_material(): void
    {
        // Base Metals is a P0 material (type_id 2267)
        $name = $this->service->resolveInputName(2267);
        $this->assertEquals('Base Metals', $name);
    }

    // ── Customs tax ────────────────────────────────────────────────

    public function test_apply_customs_tax_on_standard_tier_matches_old_js_formula(): void
    {
        $row = [
            'daily_input_cost_planet' => 1_200_000.0,
            'daily_output_sell_planet' => 2_000_000.0,
            'daily_profit_sell_planet' => 800_000.0,
        ];

        $result = $this->service->applyCustomsTax([$row], 'p2_to_p3', 5.0)[0];

        // Old JS: profit - (input + output) * taxRate / 100
        // Import 1.2M × 5% = 60,000; export 2M × 5% = 100,000; total 160,000
        $this->assertEqualsWithDelta(60_000.0, $result['daily_import_tax'], 0.001);
        $this->assertEqualsWithDelta(100_000.0, $result['daily_export_tax'], 0.001);
        $this->assertEqualsWithDelta(160_000.0, $result['daily_tax_total'], 0.001);
        $this->assertEqualsWithDelta(640_000.0, $result['daily_profit_after_tax'], 0.001);
        // Existing fields are untouched
        $this->assertEquals(800_000.0, $result['daily_profit_sell_planet']);
    }

    public function test_apply_customs_tax_on_chain_tiers_uses_import_and_export_values(): void
    {
        $row = [
            'daily_import_value' => 3_000_000.0,
            'daily_export_value' => 5_000_000.0,
            'daily_profit_sell' => 2_000_000.0,
            // Per-planet fields are ignored for chain tiers
            'daily_input_cost_planet' => 999.0,
            'daily_output_sell_planet' => 999.0,
        ];

        foreach (['p1_to_p3', 'p0_to_p3'] as $tier) {
            $result = $this->service->applyCustomsTax([$row], $tier, 10.0)[0];

            // Old JS: profit_sell - import × rate - export × rate
            $this->assertEqualsWithDelta(300_000.0, $result['daily_import_tax'], 0.001);
            $this->assertEqualsWithDelta(500_000.0, $result['daily_export_tax'], 0.001);
            $this->assertEqualsWithDelta(800_000.0, $result['daily_tax_total'], 0.001);
            $this->assertEqualsWithDelta(1_200_000.0, $result['daily_profit_after_tax'], 0.001);
        }
    }

    public function test_apply_customs_tax_at_zero_rate_keeps_profit(): void
    {
        $row = ['daily_input_cost_planet' => 100.0, 'daily_output_sell_planet' => 300.0, 'daily_profit_sell_planet' => 200.0];

        $result = $this->service->applyCustomsTax([$row], 'p1_to_p2', 0.0)[0];

        $this->assertEquals(0.0, $result['daily_tax_total']);
        $this->assertEquals(200.0, $result['daily_profit_after_tax']);
    }
}
