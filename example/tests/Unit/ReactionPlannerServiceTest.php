<?php

namespace Tests\Unit;

use App\Services\EsiService;
use App\Services\MarketService;
use App\Services\ReactionPlannerService;
use App\Services\ReactionsCalculatorService;
use Mockery;
use Tests\TestCase;

class ReactionPlannerServiceTest extends TestCase
{
    private ReactionPlannerService $service;

    private ReactionsCalculatorService $calculator;

    private $esi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esi = Mockery::mock(EsiService::class);
        $market = Mockery::mock(MarketService::class);
        $this->calculator = new ReactionsCalculatorService($this->esi, $market);
        $this->service = new ReactionPlannerService($this->esi, $this->calculator);
    }

    // ── aggregateMaterials ──────────────────────────────────────────

    public function test_aggregate_materials_combines_same_type(): void
    {
        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
            ['type_id' => 16635, 'name' => 'Evaporite Deposits', 'quantity' => 200],
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 150],
        ];

        $result = $this->service->aggregateMaterials($materials);

        $this->assertCount(2, $result);

        $atmoGas = collect($result)->firstWhere('type_id', 16634);
        $this->assertEquals(250, $atmoGas['quantity']);
        $this->assertEquals('Atmospheric Gases', $atmoGas['name']);

        $evaporite = collect($result)->firstWhere('type_id', 16635);
        $this->assertEquals(200, $evaporite['quantity']);
    }

    public function test_aggregate_materials_empty_array(): void
    {
        $result = $this->service->aggregateMaterials([]);
        $this->assertCount(0, $result);
    }

    public function test_aggregate_materials_single_item(): void
    {
        $materials = [
            ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
        ];

        $result = $this->service->aggregateMaterials($materials);

        $this->assertCount(1, $result);
        $this->assertEquals(100, $result[0]['quantity']);
    }

    // ── resolveChain ────────────────────────────────────────────────

    public function test_resolve_chain_single_entry_no_intermediates(): void
    {
        // Mock a simple reaction with raw materials only (no intermediates in DB)
        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99001, 11)
            ->andReturn([
                'materials' => [
                    ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
                    ['type_id' => 16635, 'name' => 'Evaporite Deposits', 'quantity' => 200],
                ],
                'products' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 200],
                ],
                'time' => 3600,
            ]);

        // Mock that neither input material has its own reaction (they're raw)
        $mock = Mockery::mock(ReactionPlannerService::class, [$this->esi, $this->calculator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('findReactionForProduct')
            ->with(16634)
            ->andReturn(null);
        $mock->shouldReceive('findReactionForProduct')
            ->with(16635)
            ->andReturn(null);

        $result = $mock->resolveChain([
            [
                'formula_type_id' => 99001,
                'slots' => 2,
                'runs_per_slot' => 240,
            ],
        ]);

        // Should have composite outputs
        $this->assertCount(1, $result['composite_outputs']);
        $this->assertEquals(480, $result['composite_outputs'][0]['total_runs']);
        $this->assertEquals(96000, $result['composite_outputs'][0]['total_output']); // 480 * 200

        // Should have composite slots
        $this->assertEquals(2, $result['total_composite_slots']);

        // Should have no intermediate slots (all raw materials)
        $this->assertEquals(0, $result['total_intermediate_slots']);

        // Should have 2 raw materials
        $this->assertCount(2, $result['raw_materials']);
    }

    public function test_resolve_chain_empty_entries(): void
    {
        $result = $this->service->resolveChain([]);

        $this->assertCount(0, $result['composite_outputs']);
        $this->assertCount(0, $result['raw_materials']);
        $this->assertEquals(0, $result['total_slots']);
    }

    public function test_resolve_chain_skips_invalid_formula(): void
    {
        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99999, 11)
            ->andReturn(null);

        $result = $this->service->resolveChain([
            [
                'formula_type_id' => 99999,
                'slots' => 1,
                'runs_per_slot' => 240,
            ],
        ]);

        $this->assertCount(0, $result['composite_outputs']);
        $this->assertEquals(0, $result['total_slots']);
    }

    public function test_resolve_chain_with_custom_rig_bonus(): void
    {
        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99001, 11)
            ->andReturn([
                'materials' => [
                    ['type_id' => 16634, 'name' => 'Atmospheric Gases', 'quantity' => 100],
                ],
                'products' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 200],
                ],
                'time' => 3600,
            ]);

        $mock = Mockery::mock(ReactionPlannerService::class, [$this->esi, $this->calculator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('findReactionForProduct')
            ->with(16634)
            ->andReturn(null);

        // With 2.4% T1 rig bonus, per-entry override
        $result = $mock->resolveChain([
            [
                'formula_type_id' => 99001,
                'slots' => 1,
                'runs_per_slot' => 10,
                'rig_bonus' => 2.4,
            ],
        ]);

        // 100 * 10 * 0.976 = 976 → ceil = 976
        $rawQty = $result['raw_materials'][0]['quantity'];
        $this->assertEquals(976, $rawQty);
    }

    // ── resolveChain with intermediates ─────────────────────────────

    public function test_resolve_chain_with_intermediate_reactions(): void
    {
        // Composite reaction: needs intermediate + fuel
        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99001, 11)
            ->andReturn([
                'materials' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 100],        // intermediate
                    ['type_id' => 4312, 'name' => 'Helium Fuel Block', 'quantity' => 5], // raw
                ],
                'products' => [
                    ['type_id' => 33362, 'name' => 'Sylramic Fibers', 'quantity' => 300],
                ],
                'time' => 3600,
            ]);

        $mock = Mockery::mock(ReactionPlannerService::class, [$this->esi, $this->calculator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Hexite is an intermediate - has its own reaction
        $mock->shouldReceive('findReactionForProduct')
            ->with(17317)
            ->andReturn([
                'blueprint_type_id' => 99002,
                'materials' => [
                    ['type_id' => 16640, 'name' => 'Chromium', 'quantity' => 100],
                    ['type_id' => 16644, 'name' => 'Platinum', 'quantity' => 100],
                ],
                'products' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 200],
                ],
                'time' => 3600,
            ]);

        // Helium Fuel Block is raw
        $mock->shouldReceive('findReactionForProduct')
            ->with(4312)
            ->andReturn(null);

        $result = $mock->resolveChain([
            [
                'formula_type_id' => 99001,
                'slots' => 1,
                'runs_per_slot' => 10,
            ],
        ]);

        // Should have 1 composite slot
        $this->assertEquals(1, $result['total_composite_slots']);

        // Should have intermediate slots for Hexite
        $this->assertGreaterThan(0, $result['total_intermediate_slots']);

        // Raw materials should include Chromium, Platinum, and Helium Fuel Block
        $rawTypeIds = array_column($result['raw_materials'], 'type_id');
        $this->assertContains(16640, $rawTypeIds); // Chromium
        $this->assertContains(16644, $rawTypeIds); // Platinum
        $this->assertContains(4312, $rawTypeIds);  // Helium Fuel Block

        // Total slots should be composite + intermediate
        $this->assertEquals(
            $result['total_composite_slots'] + $result['total_intermediate_slots'],
            $result['total_slots']
        );
    }

    public function test_resolve_chain_multiple_composites_aggregate_materials(): void
    {
        // Two composites that share some raw materials
        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99001, 11)
            ->andReturn([
                'materials' => [
                    ['type_id' => 16640, 'name' => 'Chromium', 'quantity' => 100],
                ],
                'products' => [
                    ['type_id' => 33361, 'name' => 'Composite A', 'quantity' => 100],
                ],
                'time' => 3600,
            ]);

        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99002, 11)
            ->andReturn([
                'materials' => [
                    ['type_id' => 16640, 'name' => 'Chromium', 'quantity' => 50],
                ],
                'products' => [
                    ['type_id' => 33362, 'name' => 'Composite B', 'quantity' => 100],
                ],
                'time' => 3600,
            ]);

        $mock = Mockery::mock(ReactionPlannerService::class, [$this->esi, $this->calculator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('findReactionForProduct')
            ->with(16640)
            ->andReturn(null);

        $result = $mock->resolveChain([
            ['formula_type_id' => 99001, 'slots' => 1, 'runs_per_slot' => 10],
            ['formula_type_id' => 99002, 'slots' => 1, 'runs_per_slot' => 10],
        ]);

        // Should have 2 composite outputs
        $this->assertCount(2, $result['composite_outputs']);

        // Chromium should be aggregated: 100*10 + 50*10 = 1500
        $chromium = collect($result['raw_materials'])->firstWhere('type_id', 16640);
        $this->assertEquals(1500, $chromium['quantity']);

        // Total composite slots = 2
        $this->assertEquals(2, $result['total_composite_slots']);
    }

    // ── intermediate run calculation (bug fix) ──────────────────────

    public function test_intermediate_raw_materials_based_on_actual_runs_needed(): void
    {
        // Composite: 1 slot × 200 runs, needs 100 Hexite per run
        // With no rig bonus: 100 * 200 = 20,000 Hexite needed
        // Hexite produces 200 per run → ceil(20,000 / 200) = 100 intermediate runs needed
        // Raw materials should be for 100 runs, NOT rounded up to 200 (runsPerSlot)
        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99001, 11)
            ->andReturn([
                'materials' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 100],
                ],
                'products' => [
                    ['type_id' => 33362, 'name' => 'Sylramic Fibers', 'quantity' => 300],
                ],
                'time' => 3600,
            ]);

        $mock = Mockery::mock(ReactionPlannerService::class, [$this->esi, $this->calculator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // Hexite is an intermediate
        $mock->shouldReceive('findReactionForProduct')
            ->with(17317)
            ->andReturn([
                'blueprint_type_id' => 99002,
                'materials' => [
                    ['type_id' => 16640, 'name' => 'Chromium', 'quantity' => 100],
                    ['type_id' => 16644, 'name' => 'Platinum', 'quantity' => 100],
                ],
                'products' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 200],
                ],
                'time' => 3600,
            ]);

        $result = $mock->resolveChain([
            [
                'formula_type_id' => 99001,
                'slots' => 1,
                'runs_per_slot' => 200,
            ],
        ]);

        // Intermediate needs exactly 100 runs (20,000 / 200)
        $intermediateSlot = $result['intermediate_slots'][0];
        $this->assertEquals(100, $intermediateSlot['total_runs']);
        $this->assertEquals(1, $intermediateSlot['slots']);

        // Raw materials should be for 100 intermediate runs: 100 * 100 = 10,000 each
        $chromium = collect($result['raw_materials'])->firstWhere('type_id', 16640);
        $platinum = collect($result['raw_materials'])->firstWhere('type_id', 16644);
        $this->assertEquals(10000, $chromium['quantity']);
        $this->assertEquals(10000, $platinum['quantity']);
    }

    public function test_intermediate_runs_not_inflated_to_composite_runs_per_slot(): void
    {
        // Composite: 1 slot × 10 runs, needs 100 Hexite per run
        // With no rig: 100 * 10 = 1,000 Hexite needed
        // Hexite produces 200 per run → ceil(1,000 / 200) = 5 intermediate runs
        // Bug was: ceil(5 / 10) * 10 = 10 (rounded up to runsPerSlot), doubling cost
        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99001, 11)
            ->andReturn([
                'materials' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 100],
                    ['type_id' => 4312, 'name' => 'Helium Fuel Block', 'quantity' => 5],
                ],
                'products' => [
                    ['type_id' => 33362, 'name' => 'Sylramic Fibers', 'quantity' => 300],
                ],
                'time' => 3600,
            ]);

        $mock = Mockery::mock(ReactionPlannerService::class, [$this->esi, $this->calculator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('findReactionForProduct')
            ->with(17317)
            ->andReturn([
                'blueprint_type_id' => 99002,
                'materials' => [
                    ['type_id' => 16640, 'name' => 'Chromium', 'quantity' => 100],
                    ['type_id' => 16644, 'name' => 'Platinum', 'quantity' => 100],
                ],
                'products' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 200],
                ],
                'time' => 3600,
            ]);

        $mock->shouldReceive('findReactionForProduct')
            ->with(4312)
            ->andReturn(null);

        $result = $mock->resolveChain([
            [
                'formula_type_id' => 99001,
                'slots' => 1,
                'runs_per_slot' => 10,
            ],
        ]);

        // Intermediate needs 5 runs (1,000 / 200), NOT 10 (rounded to runsPerSlot)
        $intermediateSlot = $result['intermediate_slots'][0];
        $this->assertEquals(5, $intermediateSlot['total_runs']);
        $this->assertEquals(1, $intermediateSlot['slots']);

        // Raw materials for 5 intermediate runs: 100 * 5 = 500 each
        $chromium = collect($result['raw_materials'])->firstWhere('type_id', 16640);
        $platinum = collect($result['raw_materials'])->firstWhere('type_id', 16644);
        $this->assertEquals(500, $chromium['quantity']);
        $this->assertEquals(500, $platinum['quantity']);

        // Fuel blocks should be unaffected: 5 * 10 = 50
        $fuelBlock = collect($result['raw_materials'])->firstWhere('type_id', 4312);
        $this->assertEquals(50, $fuelBlock['quantity']);
    }

    public function test_intermediate_runs_with_rig_bonus(): void
    {
        // Composite: 1 slot × 100 runs, needs 100 Hexite per run
        // With 2.2% rig: ceil(round(100 * 100 * 0.978, 2)) = ceil(9780) = 9,780 Hexite
        // Hexite produces 200/run → ceil(9,780 / 200) = 49 intermediate runs
        // Intermediate raw with 2.2% rig: ceil(round(100 * 49 * 0.978, 2)) = ceil(4792.2) = 4,793
        $this->esi->shouldReceive('resolveBlueprintActivity')
            ->with(99001, 11)
            ->andReturn([
                'materials' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 100],
                ],
                'products' => [
                    ['type_id' => 33362, 'name' => 'Sylramic Fibers', 'quantity' => 300],
                ],
                'time' => 3600,
            ]);

        $mock = Mockery::mock(ReactionPlannerService::class, [$this->esi, $this->calculator])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        $mock->shouldReceive('findReactionForProduct')
            ->with(17317)
            ->andReturn([
                'blueprint_type_id' => 99002,
                'materials' => [
                    ['type_id' => 16640, 'name' => 'Chromium', 'quantity' => 100],
                ],
                'products' => [
                    ['type_id' => 17317, 'name' => 'Hexite', 'quantity' => 200],
                ],
                'time' => 3600,
            ]);

        $result = $mock->resolveChain([
            [
                'formula_type_id' => 99001,
                'slots' => 1,
                'runs_per_slot' => 100,
            ],
        ], 2.2); // T1 rig in nullsec = 2.2%

        // Intermediate needs 49 runs: ceil(9780 / 200) = 49
        $intermediateSlot = $result['intermediate_slots'][0];
        $this->assertEquals(49, $intermediateSlot['total_runs']);

        // Raw Chromium: ceil(round(100 * 49 * 0.978, 2)) = ceil(4792.2) = 4793
        $chromium = collect($result['raw_materials'])->firstWhere('type_id', 16640);
        $this->assertEquals(4793, $chromium['quantity']);
    }
}
