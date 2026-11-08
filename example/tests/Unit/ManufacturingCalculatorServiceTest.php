<?php

namespace Tests\Unit;

use App\Services\EsiService;
use App\Services\ManufacturingCalculatorService;
use App\Services\MarketService;
use Mockery;
use Tests\TestCase;

class ManufacturingCalculatorServiceTest extends TestCase
{
    private ManufacturingCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $esi = Mockery::mock(EsiService::class);
        $market = Mockery::mock(MarketService::class);
        $this->service = new ManufacturingCalculatorService($esi, $market);
    }

    // ── calculateMaterials ──────────────────────────────────────────

    public function test_calculate_materials_no_me_single_run(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ['type_id' => 35, 'name' => 'Pyerite', 'quantity' => 50],
        ];

        $result = $this->service->calculateMaterials($base, meLevel: 0, runs: 1);

        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]['adjusted_quantity']);
        $this->assertEquals(50, $result[1]['adjusted_quantity']);
        $this->assertEquals(100, $result[0]['base_quantity']);
    }

    public function test_calculate_materials_with_me10_single_run(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
        ];

        // ME10: 100 * 1 * 0.90 = 90
        $result = $this->service->calculateMaterials($base, meLevel: 10, runs: 1);

        $this->assertEquals(90, $result[0]['adjusted_quantity']);
    }

    public function test_calculate_materials_multiple_runs(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
        ];

        // ME0, 10 runs: 100 * 10 * 1.0 = 1000
        $result = $this->service->calculateMaterials($base, meLevel: 0, runs: 10);

        $this->assertEquals(1000, $result[0]['adjusted_quantity']);
        $this->assertEquals(100, $result[0]['quantity_per_run']);
    }

    public function test_calculate_materials_with_me_and_structure_bonus(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
        ];

        // ME10 + 1% structure: 100 * 1 * 0.90 * 0.99 = 89.1 → ceil = 90
        $result = $this->service->calculateMaterials($base, meLevel: 10, runs: 1, structureMeBonus: 1.0);

        $this->assertEquals(90, $result[0]['adjusted_quantity']);
    }

    public function test_calculate_materials_minimum_is_runs(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 1],
        ];

        // ME10: 1 * 5 * 0.90 = 4.5 → ceil = 5, min(5, 5) = 5
        $result = $this->service->calculateMaterials($base, meLevel: 10, runs: 5);

        $this->assertGreaterThanOrEqual(5, $result[0]['adjusted_quantity']);
    }

    public function test_calculate_materials_ceil_rounding(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 33],
        ];

        // ME5: 33 * 1 * 0.95 = 31.35 → ceil = 32
        $result = $this->service->calculateMaterials($base, meLevel: 5, runs: 1);

        $this->assertEquals(32, $result[0]['adjusted_quantity']);
    }

    public function test_calculate_materials_floating_point_precision(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 10],
        ];

        // ME10 + 1% structure: 10 * 1 * 0.90 * 0.99 = 8.91 → round(8.91, 2) = 8.91 → ceil = 9
        // Without round(..., 2), floating-point errors could cause incorrect ceiling
        $result = $this->service->calculateMaterials($base, meLevel: 10, runs: 1, structureMeBonus: 1.0);

        $this->assertEquals(9, $result[0]['adjusted_quantity']);
    }

    // ── calculateProductionTime ─────────────────────────────────────

    public function test_calculate_production_time_no_bonuses(): void
    {
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            teLevel: 0,
            runs: 1
        );

        $this->assertEquals(3600, $result['base_time_seconds']);
        $this->assertEquals(3600, $result['time_per_run_seconds']);
        $this->assertEquals(3600, $result['total_time_seconds']);
    }

    public function test_calculate_production_time_with_te10(): void
    {
        // TE10 = 10% reduction: 3600 * 0.90 = 3240
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            teLevel: 10,
            runs: 1
        );

        $this->assertEquals(3240, $result['time_per_run_seconds']);
    }

    public function test_calculate_production_time_with_te20(): void
    {
        // TE20 = 20% reduction (maximum): 3600 * 0.80 = 2880
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            teLevel: 20,
            runs: 1
        );

        $this->assertEquals(2880, $result['time_per_run_seconds']);
    }

    public function test_calculate_production_time_with_skills(): void
    {
        // Industry 5 = 20% reduction: 3600 * 0.80 = 2880
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            teLevel: 0,
            runs: 1,
            industrySkill: 5
        );

        $this->assertEquals(2880, $result['time_per_run_seconds']);
    }

    public function test_calculate_production_time_with_all_bonuses(): void
    {
        // TE20(0.80) * Industry5(0.80) * AdvIndustry5(0.85) * Structure3%(0.97)
        // 3600 * 0.80 * 0.80 * 0.85 * 0.97 = 1899.648 → round = 1900
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            teLevel: 20,
            runs: 1,
            industrySkill: 5,
            advancedIndustrySkill: 5,
            structureTeBonus: 3.0
        );

        $this->assertEquals(1900, $result['time_per_run_seconds']);
    }

    public function test_calculate_production_time_multiple_runs(): void
    {
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            teLevel: 0,
            runs: 5
        );

        $this->assertEquals(3600, $result['time_per_run_seconds']);
        $this->assertEquals(18000, $result['total_time_seconds']);
    }

    public function test_calculate_production_time_formatted_output(): void
    {
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            teLevel: 0,
            runs: 1
        );

        $this->assertEquals('1h 0m 0s', $result['time_per_run_formatted']);
    }

    // ── formatDuration ──────────────────────────────────────────────

    public function test_format_duration_seconds_only(): void
    {
        $this->assertEquals('45s', $this->service->formatDuration(45));
    }

    public function test_format_duration_minutes_and_seconds(): void
    {
        // 125 seconds = 2m 5s
        $this->assertEquals('2m 5s', $this->service->formatDuration(125));
    }

    public function test_format_duration_hours(): void
    {
        // 3661 seconds = 1h 1m 1s
        $this->assertEquals('1h 1m 1s', $this->service->formatDuration(3661));
    }

    public function test_format_duration_days(): void
    {
        // 90061 seconds = 1d 1h 1m 1s
        $this->assertEquals('1d 1h 1m 1s', $this->service->formatDuration(90061));
    }

    public function test_format_duration_zero(): void
    {
        $this->assertEquals('0s', $this->service->formatDuration(0));
    }

    public function test_format_duration_exact_hour(): void
    {
        $this->assertEquals('1h 0m 0s', $this->service->formatDuration(3600));
    }

    // ── Rig & structure bonus constants ─────────────────────────────

    public function test_rig_me_constants(): void
    {
        $this->assertEquals(2.0, ManufacturingCalculatorService::RIG_ME_T1);
        $this->assertEquals(2.4, ManufacturingCalculatorService::RIG_ME_T2);
    }

    public function test_rig_te_constants(): void
    {
        $this->assertEquals(20.0, ManufacturingCalculatorService::RIG_TE_T1);
        $this->assertEquals(24.0, ManufacturingCalculatorService::RIG_TE_T2);
    }

    public function test_security_multiplier_constants(): void
    {
        $this->assertEquals(1.0, ManufacturingCalculatorService::SECURITY_MULTIPLIER_HIGHSEC);
        $this->assertEquals(1.9, ManufacturingCalculatorService::SECURITY_MULTIPLIER_LOWSEC);
        $this->assertEquals(2.1, ManufacturingCalculatorService::SECURITY_MULTIPLIER_NULLSEC);
    }

    public function test_t2_rig_me_highsec_bonus(): void
    {
        // T2 ME rig in highsec: 2.4% * 1.0 = 2.4%
        $bonus = ManufacturingCalculatorService::RIG_ME_T2 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_HIGHSEC;
        $this->assertEqualsWithDelta(2.4, $bonus, 0.001);
    }

    public function test_t2_rig_me_lowsec_bonus(): void
    {
        // T2 ME rig in lowsec: 2.4% * 1.9 = 4.56%
        $bonus = ManufacturingCalculatorService::RIG_ME_T2 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_LOWSEC;
        $this->assertEqualsWithDelta(4.56, $bonus, 0.001);
    }

    public function test_t2_rig_me_nullsec_bonus(): void
    {
        // T2 ME rig in nullsec: 2.4% * 2.1 = 5.04%
        $bonus = ManufacturingCalculatorService::RIG_ME_T2 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_NULLSEC;
        $this->assertEqualsWithDelta(5.04, $bonus, 0.001);
    }

    public function test_t2_rig_te_highsec_bonus(): void
    {
        // T2 TE rig in highsec: 24% * 1.0 = 24%
        $bonus = ManufacturingCalculatorService::RIG_TE_T2 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_HIGHSEC;
        $this->assertEqualsWithDelta(24.0, $bonus, 0.001);
    }

    public function test_t2_rig_te_lowsec_bonus(): void
    {
        // T2 TE rig in lowsec: 24% * 1.9 = 45.6%
        $bonus = ManufacturingCalculatorService::RIG_TE_T2 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_LOWSEC;
        $this->assertEqualsWithDelta(45.6, $bonus, 0.001);
    }

    public function test_t2_rig_te_nullsec_bonus(): void
    {
        // T2 TE rig in nullsec: 24% * 2.1 = 50.4%
        $bonus = ManufacturingCalculatorService::RIG_TE_T2 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_NULLSEC;
        $this->assertEqualsWithDelta(50.4, $bonus, 0.001);
    }

    public function test_calculate_materials_with_t2_rig_nullsec(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
        ];

        // ME10 + structure 1% + T2 rig nullsec (5.04%), combined multiplicatively.
        // Effective structure bonus = (1 - 0.99 * 0.9496) * 100 ≈ 5.9896%
        // 100 * 1 * 0.90 * (1 - 0.059896) = 90 * 0.940104 = 84.60936 → ceil = 85
        $totalMeBonus = ManufacturingCalculatorService::combineMeBonuses(
            ManufacturingCalculatorService::SOTIYO_ME_BONUS,
            ManufacturingCalculatorService::RIG_ME_T2 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_NULLSEC
        );
        $result = $this->service->calculateMaterials($base, meLevel: 10, runs: 1, structureMeBonus: $totalMeBonus);

        $this->assertEquals(85, $result[0]['adjusted_quantity']);
    }

    public function test_combine_me_bonuses_is_multiplicative_not_additive(): void
    {
        // Role 1% and rig 3.8% must combine multiplicatively, not summed to 4.8%.
        $combined = ManufacturingCalculatorService::combineMeBonuses(1.0, 3.8);

        // (1 - 0.99 * 0.962) * 100 = 4.762
        $this->assertEqualsWithDelta(4.762, $combined, 0.0001);
        $this->assertNotEqualsWithDelta(4.8, $combined, 0.0001);
    }

    public function test_combine_me_bonuses_empty_is_zero(): void
    {
        $this->assertEquals(0.0, ManufacturingCalculatorService::combineMeBonuses());
    }

    public function test_calculate_materials_bug_report_scenario(): void
    {
        // Bug report: Core Temperature Regulator BP 10/20, 10 runs, LS station with T1 rigs.
        // ME 10, role bonus 1%, rig bonus 3.8% (T1 rig 2.0 * lowsec 1.9).
        // Role and rig combine multiplicatively → effective structure bonus ≈ 4.762%.
        $base = [
            ['type_id' => 1, 'name' => 'Chiral Structures', 'quantity' => 100],
            ['type_id' => 2, 'name' => 'Water', 'quantity' => 100],
            ['type_id' => 3, 'name' => 'Pressurized Oxidizers', 'quantity' => 500],
            ['type_id' => 4, 'name' => 'Reinforced Carbon Fiber', 'quantity' => 500],
        ];

        $structureMeBonus = ManufacturingCalculatorService::combineMeBonuses(
            ManufacturingCalculatorService::RAITARU_ME_BONUS,
            ManufacturingCalculatorService::RIG_ME_T1 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_LOWSEC
        );

        $result = $this->service->calculateMaterials($base, meLevel: 10, runs: 10, structureMeBonus: $structureMeBonus);

        // Matches the actual EVE industry window values, not the previous 857/4284.
        $this->assertEquals(858, $result[0]['adjusted_quantity']);
        $this->assertEquals(858, $result[1]['adjusted_quantity']);
        $this->assertEquals(4286, $result[2]['adjusted_quantity']);
        $this->assertEquals(4286, $result[3]['adjusted_quantity']);
    }

    public function test_calculate_production_time_with_t2_rig_nullsec(): void
    {
        // TE20(0.80) * Industry5(0.80) * AdvIndustry5(0.85) * Structure TE (3% + 50.4% = 53.4% → 0.466)
        // 3600 * 0.80 * 0.80 * 0.85 * 0.466 = 912.6144 → round = 913
        $totalTeBonus = ManufacturingCalculatorService::SOTIYO_TE_BONUS
            + ManufacturingCalculatorService::RIG_TE_T2 * ManufacturingCalculatorService::SECURITY_MULTIPLIER_NULLSEC;
        $result = $this->service->calculateProductionTime(
            baseTimeSeconds: 3600,
            teLevel: 20,
            runs: 1,
            industrySkill: 5,
            advancedIndustrySkill: 5,
            structureTeBonus: $totalTeBonus
        );

        $this->assertEquals(913, $result['time_per_run_seconds']);
    }

    // ── calculateCostProfit ──────────────────────────────────────────

    /**
     * One entry as returned by MarketService::getSellPricesWithSource().
     */
    private function sellPrice(float $price, string $source = MarketService::PRICE_SOURCE_ORDERS): array
    {
        return ['price' => $price, 'source' => $source];
    }

    public function test_calculate_cost_profit_no_buyback(): void
    {
        $materials = [
            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 100],
        ];

        $market = Mockery::mock(\App\Services\MarketService::class);
        $market->shouldReceive('getSellPricesWithSource')
            ->once()
            ->andReturn([
                34 => $this->sellPrice(10.0),
                99 => $this->sellPrice(1000.0),
            ]);

        $esi = Mockery::mock(\App\Services\EsiService::class);
        $service = new ManufacturingCalculatorService($esi, $market);

        $result = $service->calculateCostProfit($materials, productTypeId: 99, runs: 1);

        // Without buyback, unit_price = sell_min = 10.0
        $this->assertEquals(10.0, $result['materials'][0]['unit_price']);
        $this->assertEquals(1000.0, $result['total_material_cost']);
    }

    public function test_calculate_cost_profit_with_90_percent_buyback(): void
    {
        $materials = [
            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 100],
        ];

        $market = Mockery::mock(\App\Services\MarketService::class);
        $market->shouldReceive('getSellPricesWithSource')
            ->once()
            ->andReturn([
                34 => $this->sellPrice(10.0),
                99 => $this->sellPrice(1000.0),
            ]);

        $esi = Mockery::mock(\App\Services\EsiService::class);
        $service = new ManufacturingCalculatorService($esi, $market);

        $result = $service->calculateCostProfit($materials, productTypeId: 99, runs: 1, buybackPercentage: 90.0);

        // With 90% buyback: unit_price = 10.0 * 0.90 = 9.0; total = 9.0 * 100 = 900
        $this->assertEqualsWithDelta(9.0, $result['materials'][0]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(900.0, $result['total_material_cost'], 0.001);
    }

    public function test_calculate_cost_profit_with_100_percent_buyback_unchanged(): void
    {
        $materials = [
            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 50],
        ];

        $market = Mockery::mock(\App\Services\MarketService::class);
        $market->shouldReceive('getSellPricesWithSource')
            ->once()
            ->andReturn([
                34 => $this->sellPrice(20.0),
                99 => $this->sellPrice(500.0),
            ]);

        $esi = Mockery::mock(\App\Services\EsiService::class);
        $service = new ManufacturingCalculatorService($esi, $market);

        $result = $service->calculateCostProfit($materials, productTypeId: 99, runs: 1, buybackPercentage: 100.0);

        // 100% buyback = full price, no reduction
        $this->assertEqualsWithDelta(20.0, $result['materials'][0]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(1000.0, $result['total_material_cost'], 0.001);
    }

    public function test_calculate_cost_profit_with_zero_buyback(): void
    {
        $materials = [
            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 100],
        ];

        $market = Mockery::mock(\App\Services\MarketService::class);
        $market->shouldReceive('getSellPricesWithSource')
            ->once()
            ->andReturn([
                34 => $this->sellPrice(10.0),
                99 => $this->sellPrice(500.0),
            ]);

        $esi = Mockery::mock(\App\Services\EsiService::class);
        $service = new ManufacturingCalculatorService($esi, $market);

        $result = $service->calculateCostProfit($materials, productTypeId: 99, runs: 1, buybackPercentage: 0.0);

        // 0% buyback = free materials
        $this->assertEqualsWithDelta(0.0, $result['materials'][0]['unit_price'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['total_material_cost'], 0.001);
    }

    public function test_calculate_cost_profit_applies_scc_fee_and_margin_on_input_cost(): void
    {
        $materials = [
            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 100],
        ];

        $market = Mockery::mock(MarketService::class);
        $market->shouldReceive('getSellPricesWithSource')
            ->once()
            ->andReturn([
                34 => $this->sellPrice(10.0),
                99 => $this->sellPrice(1000.0, MarketService::PRICE_SOURCE_FALLBACK),
            ]);

        $service = new ManufacturingCalculatorService(Mockery::mock(EsiService::class), $market);

        $result = $service->calculateCostProfit($materials, productTypeId: 99, runs: 2);

        // Cost 1000; revenue 2 × 1000 = 2000; SCC 4% = 80; profit 920; margin 920/1000 = 92%
        $this->assertEqualsWithDelta(80.0, $result['scc_fee'], 0.001);
        $this->assertEqualsWithDelta(920.0, $result['profit'], 0.001);
        $this->assertEquals(92.0, $result['profit_margin']);
        $this->assertEquals(MarketService::PRICE_SOURCE_ORDERS, $result['materials'][0]['price_source']);
        $this->assertEquals(MarketService::PRICE_SOURCE_FALLBACK, $result['product_price_source']);
    }
}
