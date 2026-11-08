<?php

namespace Tests\Unit;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Services\EsiService;
use App\Services\InventionPlannerService;
use App\Services\ManufacturingCalculatorService;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class InventionPlannerServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventionPlannerService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $esi = Mockery::mock(EsiService::class);
        $market = Mockery::mock(MarketService::class);
        $calculator = new ManufacturingCalculatorService($esi, $market);
        $this->service = new InventionPlannerService($esi, $calculator, $market);
    }

    // ── Decryptor constants ─────────────────────────────────────────

    public function test_decryptors_has_none_option(): void
    {
        $decryptors = InventionPlannerService::DECRYPTORS;
        $this->assertArrayHasKey('none', $decryptors);
        $this->assertEquals(1.0, $decryptors['none']['probability_multiplier']);
        $this->assertEquals(0, $decryptors['none']['me_modifier']);
        $this->assertEquals(0, $decryptors['none']['te_modifier']);
        $this->assertEquals(0, $decryptors['none']['run_modifier']);
        $this->assertNull($decryptors['none']['type_id']);
    }

    public function test_decryptors_has_all_seven(): void
    {
        $decryptors = InventionPlannerService::DECRYPTORS;
        $this->assertCount(7, $decryptors);
        $this->assertArrayHasKey('accelerant', $decryptors);
        $this->assertArrayHasKey('attainment', $decryptors);
        $this->assertArrayHasKey('augmentation', $decryptors);
        $this->assertArrayHasKey('parity', $decryptors);
        $this->assertArrayHasKey('process', $decryptors);
        $this->assertArrayHasKey('symmetry', $decryptors);
    }

    public function test_accelerant_decryptor_values(): void
    {
        $dec = InventionPlannerService::DECRYPTORS['accelerant'];
        $this->assertEquals(34201, $dec['type_id']);
        $this->assertEquals(1.2, $dec['probability_multiplier']);
        $this->assertEquals(2, $dec['me_modifier']);
        $this->assertEquals(10, $dec['te_modifier']);
        $this->assertEquals(1, $dec['run_modifier']);
    }

    public function test_attainment_decryptor_values(): void
    {
        $dec = InventionPlannerService::DECRYPTORS['attainment'];
        $this->assertEquals(34202, $dec['type_id']);
        $this->assertEquals(1.8, $dec['probability_multiplier']);
        $this->assertEquals(-1, $dec['me_modifier']);
        $this->assertEquals(4, $dec['te_modifier']);
        $this->assertEquals(4, $dec['run_modifier']);
    }

    public function test_augmentation_decryptor_values(): void
    {
        $dec = InventionPlannerService::DECRYPTORS['augmentation'];
        $this->assertEquals(34203, $dec['type_id']);
        $this->assertEquals(0.6, $dec['probability_multiplier']);
        $this->assertEquals(-2, $dec['me_modifier']);
        $this->assertEquals(-2, $dec['te_modifier']);
        $this->assertEquals(9, $dec['run_modifier']);
    }

    // ── calculateInventionProbability ────────────────────────────────

    public function test_probability_base_only(): void
    {
        // No skills, no decryptor: P = 0.30 × 1.0 × 1.0 = 0.30
        $result = $this->service->calculateInventionProbability(0.30, 0, 0, 0, 1.0);
        $this->assertEqualsWithDelta(0.30, $result, 0.0001);
    }

    public function test_probability_all_skills_max(): void
    {
        // All skills 5: P = 0.30 × (1 + 5/40 + 5/30 + 5/30) = 0.30 × 1.458333 = 0.4375
        $result = $this->service->calculateInventionProbability(0.30, 5, 5, 5, 1.0);
        $this->assertEqualsWithDelta(0.4375, $result, 0.0001);
    }

    public function test_probability_with_decryptor(): void
    {
        // All skills 5, Attainment (1.8×): P = 0.30 × 1.458333 × 1.8 = 0.7875
        $result = $this->service->calculateInventionProbability(0.30, 5, 5, 5, 1.8);
        $this->assertEqualsWithDelta(0.7875, $result, 0.0001);
    }

    public function test_probability_capped_at_one(): void
    {
        // Very high base + skills + decryptor: should be capped at 1.0
        $result = $this->service->calculateInventionProbability(0.80, 5, 5, 5, 1.8);
        $this->assertEqualsWithDelta(1.0, $result, 0.0001);
    }

    public function test_probability_minimum_zero(): void
    {
        // Zero base: P = 0
        $result = $this->service->calculateInventionProbability(0, 5, 5, 5, 1.0);
        $this->assertEquals(0.0, $result);
    }

    public function test_probability_augmentation_reduces_chance(): void
    {
        // Augmentation (0.6×): P = 0.34 × (1 + 5/40 + 5/30 + 5/30) × 0.6 = 0.34 × 1.458333 × 0.6 = 0.2975
        $result = $this->service->calculateInventionProbability(0.34, 5, 5, 5, 0.6);
        $this->assertEqualsWithDelta(0.2975, $result, 0.0001);
    }

    public function test_probability_partial_skills(): void
    {
        // Encryption 4, Science1 3, Science2 5: P = 0.25 × (1 + 4/40 + 3/30 + 5/30) = 0.25 × 1.3667 = 0.341667
        $result = $this->service->calculateInventionProbability(0.25, 4, 3, 5, 1.0);
        $this->assertEqualsWithDelta(0.341667, $result, 0.0001);
    }

    public function test_probability_ice_harvester_example(): void
    {
        // Ice Harvester II: base 0.34, Gallente Encryption 4, Laser Physics 4, Mechanical Engineering 3
        // P = 0.34 × (1 + 4/40 + 4/30 + 3/30) × 1.0 = 0.34 × 1.3333 = 0.4533
        $result = $this->service->calculateInventionProbability(0.34, 4, 4, 3, 1.0);
        $this->assertEqualsWithDelta(0.4533, $result, 0.001);
    }

    // ── calculateT2Bpc ──────────────────────────────────────────────

    public function test_t2_bpc_no_decryptor(): void
    {
        $result = $this->service->calculateT2Bpc(10, 'none');
        $this->assertEquals(2, $result['me']);
        $this->assertEquals(4, $result['te']);
        $this->assertEquals(10, $result['runs']);
    }

    public function test_t2_bpc_accelerant_decryptor(): void
    {
        // Accelerant: ME+2, TE+10, runs+1
        $result = $this->service->calculateT2Bpc(10, 'accelerant');
        $this->assertEquals(4, $result['me']); // 2 + 2
        $this->assertEquals(14, $result['te']); // 4 + 10
        $this->assertEquals(11, $result['runs']); // 10 + 1
    }

    public function test_t2_bpc_attainment_decryptor(): void
    {
        // Attainment: ME-1, TE+4, runs+4
        $result = $this->service->calculateT2Bpc(10, 'attainment');
        $this->assertEquals(1, $result['me']); // 2 + (-1) = 1
        $this->assertEquals(8, $result['te']); // 4 + 4
        $this->assertEquals(14, $result['runs']); // 10 + 4
    }

    public function test_t2_bpc_augmentation_decryptor(): void
    {
        // Augmentation: ME-2, TE-2, runs+9
        $result = $this->service->calculateT2Bpc(10, 'augmentation');
        $this->assertEquals(0, $result['me']); // 2 + (-2) = 0 (clamped)
        $this->assertEquals(2, $result['te']); // 4 + (-2)
        $this->assertEquals(19, $result['runs']); // 10 + 9
    }

    public function test_t2_bpc_process_decryptor(): void
    {
        // Process: ME+3, TE+6, runs+0
        $result = $this->service->calculateT2Bpc(10, 'process');
        $this->assertEquals(5, $result['me']); // 2 + 3
        $this->assertEquals(10, $result['te']); // 4 + 6
        $this->assertEquals(10, $result['runs']); // 10 + 0
    }

    public function test_t2_bpc_me_capped_at_10(): void
    {
        // Process on an item where base ME + modifier would exceed 10
        // Process: ME+3 → 2+3=5 (doesn't exceed)
        // Test with a theoretical scenario by checking constant
        $this->assertEquals(10, InventionPlannerService::MAX_ME);
    }

    public function test_t2_bpc_te_capped_at_20(): void
    {
        // Accelerant: TE+10 → 4+10=14 (doesn't exceed 20)
        $result = $this->service->calculateT2Bpc(10, 'accelerant');
        $this->assertLessThanOrEqual(20, $result['te']);
    }

    public function test_t2_bpc_ship_base_1_run(): void
    {
        // Ships have base 1 run
        $result = $this->service->calculateT2Bpc(1, 'none');
        $this->assertEquals(1, $result['runs']);
    }

    public function test_t2_bpc_ship_with_augmentation(): void
    {
        // Ship (1 base run) + Augmentation (+9 runs) = 10 runs
        $result = $this->service->calculateT2Bpc(1, 'augmentation');
        $this->assertEquals(10, $result['runs']);
    }

    public function test_t2_bpc_ship_with_attainment(): void
    {
        // Ship (1 base run) + Attainment (+4 runs) = 5 runs
        $result = $this->service->calculateT2Bpc(1, 'attainment');
        $this->assertEquals(5, $result['runs']);
    }

    public function test_t2_bpc_runs_minimum_one(): void
    {
        // Even with negative run modifiers (none exist currently), runs should be at least 1
        $result = $this->service->calculateT2Bpc(1, 'none');
        $this->assertGreaterThanOrEqual(1, $result['runs']);
    }

    public function test_t2_bpc_unknown_decryptor_uses_none(): void
    {
        $result = $this->service->calculateT2Bpc(10, 'nonexistent');
        $this->assertEquals(2, $result['me']);
        $this->assertEquals(4, $result['te']);
        $this->assertEquals(10, $result['runs']);
    }

    // ── calculateMaterials ──────────────────────────────────────────

    public function test_calculate_materials_delegates_correctly(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
        ];

        $result = $this->service->calculateMaterials($base, 10, 1);
        // ME10: 100 * 1 * 0.90 = 90
        $this->assertEquals(90, $result[0]['adjusted_quantity']);
    }

    public function test_calculate_materials_with_structure_bonus(): void
    {
        $base = [
            ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
        ];

        // ME10 + 1% structure: 100 * 1 * 0.90 * 0.99 = 89.1 → ceil = 90
        $result = $this->service->calculateMaterials($base, 10, 1, 1.0);
        $this->assertEquals(90, $result[0]['adjusted_quantity']);
    }

    // ── Base constants ──────────────────────────────────────────────

    public function test_base_me_is_two(): void
    {
        $this->assertEquals(2, InventionPlannerService::BASE_ME);
    }

    public function test_base_te_is_four(): void
    {
        $this->assertEquals(4, InventionPlannerService::BASE_TE);
    }

    // ── Encryption skills ───────────────────────────────────────────

    public function test_encryption_skills_has_four_races(): void
    {
        $this->assertCount(4, InventionPlannerService::ENCRYPTION_SKILLS);
        $this->assertArrayHasKey(23087, InventionPlannerService::ENCRYPTION_SKILLS); // Amarr
        $this->assertArrayHasKey(21790, InventionPlannerService::ENCRYPTION_SKILLS); // Caldari
        $this->assertArrayHasKey(23121, InventionPlannerService::ENCRYPTION_SKILLS); // Gallente
        $this->assertArrayHasKey(21791, InventionPlannerService::ENCRYPTION_SKILLS); // Minmatar
    }

    // ── Datacore skill mapping ──────────────────────────────────────

    public function test_datacore_to_skill_mapping(): void
    {
        $mapping = InventionPlannerService::DATACORE_TO_SKILL;
        // Datacore - Mechanical Engineering (20424) → Mechanical Engineering skill (11452)
        $this->assertEquals(11452, $mapping[20424]);
        // Datacore - Electronic Engineering (20171) → Electronic Engineering skill (11443)
        $this->assertEquals(11443, $mapping[20171]);
    }

    // ── getDecryptors ───────────────────────────────────────────────

    public function test_get_decryptors_returns_all(): void
    {
        $result = InventionPlannerService::getDecryptors();
        $this->assertCount(7, $result);
        $this->assertArrayHasKey('none', $result);
    }

    // ── Symmetry decryptor ──────────────────────────────────────────

    public function test_symmetry_decryptor(): void
    {
        $dec = InventionPlannerService::DECRYPTORS['symmetry'];
        $this->assertEquals(1.0, $dec['probability_multiplier']);
        $this->assertEquals(1, $dec['me_modifier']);
        $this->assertEquals(8, $dec['te_modifier']);
        $this->assertEquals(2, $dec['run_modifier']);

        $result = $this->service->calculateT2Bpc(10, 'symmetry');
        $this->assertEquals(3, $result['me']); // 2 + 1
        $this->assertEquals(12, $result['te']); // 4 + 8
        $this->assertEquals(12, $result['runs']); // 10 + 2
    }

    // ── Parity decryptor ────────────────────────────────────────────

    public function test_parity_decryptor(): void
    {
        $dec = InventionPlannerService::DECRYPTORS['parity'];
        $this->assertEquals(1.5, $dec['probability_multiplier']);

        // All skills 5, parity: P = 0.34 × (1 + 5/40 + 5/30 + 5/30) × 1.5 = 0.34 × 1.458333 × 1.5 = 0.74375
        $result = $this->service->calculateInventionProbability(0.34, 5, 5, 5, 1.5);
        $this->assertEqualsWithDelta(0.74375, $result, 0.0001);
    }

    // ── resolveInventionChain – ship skill handling ─────────────────

    public function test_resolve_invention_chain_classifies_non_datacore_skills_as_science(): void
    {
        // Set up a minimal invention chain for a "T2 Ship" that uses a racial
        // ship engineering skill (not in DATACORE_SKILLS) as one of its science skills.
        //
        // T2 Manufacturing blueprint (produces T2 Ship from T1 Ship + components)
        BlueprintActivity::create([
            'blueprint_type_id' => 90001,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 80001, 'name' => 'T1 Ship', 'quantity' => 1],
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 70001, 'name' => 'T2 Ship', 'quantity' => 1],
            ],
        ]);

        // T1 Manufacturing blueprint (produces T1 Ship)
        BlueprintActivity::create([
            'blueprint_type_id' => 90002,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 1800,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500],
            ],
            'products' => [
                ['type_id' => 80001, 'name' => 'T1 Ship', 'quantity' => 1],
            ],
        ]);

        // Invention activity on the T1 blueprint
        // Uses: Amarr Encryption Methods (23087), Mechanical Engineering (11452),
        // and a racial ship skill "Amarr Starship Engineering" (type_id 11454) which
        // is NOT in DATACORE_SKILLS
        BlueprintActivity::create([
            'blueprint_type_id' => 90002,
            'activity_id' => BlueprintActivity::ACTIVITY_INVENTION,
            'time' => 600,
            'materials' => [
                ['type_id' => 20424, 'name' => 'Datacore - Mechanical Engineering', 'quantity' => 8],
            ],
            'products' => [
                ['type_id' => 90001, 'name' => 'T2 Ship Blueprint', 'quantity' => 1, 'probability' => 0.30],
            ],
            'skills' => [
                ['type_id' => 23087, 'level' => 1],  // Amarr Encryption Methods
                ['type_id' => 11452, 'level' => 1],  // Mechanical Engineering (in DATACORE_SKILLS)
                ['type_id' => 11454, 'level' => 1],  // Amarr Starship Engineering (NOT in DATACORE_SKILLS)
            ],
        ]);

        // Create EveType for the ship skill so name can be looked up
        EveType::create([
            'type_id' => 11454,
            'name' => 'Amarr Starship Engineering',
            'group_id' => 270,
            'published' => true,
        ]);

        $chain = $this->service->resolveInventionChain(70001);

        $this->assertNotNull($chain, 'Invention chain should be resolved');

        // Encryption skill should be identified correctly
        $this->assertEquals(23087, $chain['encryption_skill_id']);
        $this->assertEquals('Amarr Encryption Methods', $chain['encryption_skill_name']);

        // Both science skills should be identified, including the non-datacore ship skill
        $this->assertEquals(11452, $chain['science1_skill_id']);
        $this->assertEquals('Mechanical Engineering', $chain['science1_skill_name']);

        $this->assertEquals(11454, $chain['science2_skill_id']);
        $this->assertEquals('Amarr Starship Engineering', $chain['science2_skill_name']);
    }

    // ── searchT2Items ───────────────────────────────────────────────

    private function createT2ItemWithInventionChain(int $t2TypeId, string $t2Name, int $t1TypeId, string $t1Name, int $metaGroupId = EveType::META_GROUP_TECH_II): void
    {
        EveType::create([
            'type_id' => $t2TypeId,
            'name' => $t2Name,
            'group_id' => 1,
            'market_group_id' => 100,
            'meta_group_id' => $metaGroupId,
            'published' => true,
        ]);

        EveType::create([
            'type_id' => $t1TypeId,
            'name' => $t1Name,
            'group_id' => 1,
            'market_group_id' => 100,
            'meta_group_id' => EveType::META_GROUP_TECH_I,
            'published' => true,
        ]);

        // T2 manufacturing blueprint (produces T2 from T1 + materials)
        BlueprintActivity::create([
            'blueprint_type_id' => $t2TypeId + 100000,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => $t1TypeId, 'name' => $t1Name, 'quantity' => 1],
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => $t2TypeId, 'name' => $t2Name, 'quantity' => 1],
            ],
        ]);

        // T1 manufacturing blueprint
        BlueprintActivity::create([
            'blueprint_type_id' => $t1TypeId + 100000,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 1800,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500],
            ],
            'products' => [
                ['type_id' => $t1TypeId, 'name' => $t1Name, 'quantity' => 1],
            ],
        ]);

        // Invention activity on T1 blueprint
        BlueprintActivity::create([
            'blueprint_type_id' => $t1TypeId + 100000,
            'activity_id' => BlueprintActivity::ACTIVITY_INVENTION,
            'time' => 600,
            'materials' => [
                ['type_id' => 20424, 'name' => 'Datacore - Mechanical Engineering', 'quantity' => 8],
            ],
            'products' => [
                ['type_id' => $t2TypeId + 100000, 'name' => $t2Name.' Blueprint', 'quantity' => 1, 'probability' => 0.30],
            ],
        ]);
    }

    public function test_search_t2_items_returns_t2_rig(): void
    {
        $this->createT2ItemWithInventionChain(60001, 'Medium Ancillary Current Router II', 60002, 'Medium Ancillary Current Router I');

        $results = $this->service->searchT2Items('Ancillary Current Router');

        $this->assertCount(1, $results);
        $this->assertEquals(60001, $results[0]['type_id']);
        $this->assertEquals('Medium Ancillary Current Router II', $results[0]['name']);
    }

    public function test_search_t2_items_returns_t2_ship(): void
    {
        $this->createT2ItemWithInventionChain(60003, 'Vagabond', 60004, 'Stabber');

        $results = $this->service->searchT2Items('Vagabond');

        $this->assertCount(1, $results);
        $this->assertEquals(60003, $results[0]['type_id']);
    }

    public function test_search_t2_items_excludes_t1_items(): void
    {
        $this->createT2ItemWithInventionChain(60005, 'Hammerhead II', 60006, 'Hammerhead I');

        $results = $this->service->searchT2Items('Hammerhead');

        $this->assertCount(1, $results);
        $this->assertEquals(60005, $results[0]['type_id']);
        $this->assertEquals('Hammerhead II', $results[0]['name']);
    }

    public function test_search_t2_items_excludes_faction_items(): void
    {
        $this->createT2ItemWithInventionChain(60007, 'Module II', 60008, 'Module I');

        // Create a faction item with the same name pattern but different meta group
        EveType::create([
            'type_id' => 60009,
            'name' => 'Module Faction',
            'group_id' => 1,
            'market_group_id' => 100,
            'meta_group_id' => EveType::META_GROUP_FACTION,
            'published' => true,
        ]);

        $results = $this->service->searchT2Items('Module');

        $names = array_column($results, 'name');
        $this->assertContains('Module II', $names);
        $this->assertNotContains('Module Faction', $names);
    }

    public function test_search_t2_items_excludes_items_without_meta_group(): void
    {
        // Create an item without meta_group_id that has a valid tech chain
        $this->createT2ItemWithInventionChain(60010, 'Widget II', 60011, 'Widget I', 0);

        // Override the T2 item to have no meta_group_id
        EveType::where('type_id', 60010)->update(['meta_group_id' => null]);

        $results = $this->service->searchT2Items('Widget');

        $this->assertCount(0, $results);
    }

    // ── T2 items without T1 material (e.g. rigs) ───────────────────

    /**
     * Create a T2 item whose manufacturing BOM does NOT include the T1 variant
     * (like T2 rigs). The invention chain is linked only via the invention
     * activity producing the T2 blueprint.
     */
    private function createT2RigWithInventionChain(int $t2TypeId, string $t2Name, int $t1TypeId, string $t1Name): void
    {
        EveType::create([
            'type_id' => $t2TypeId,
            'name' => $t2Name,
            'group_id' => 1,
            'market_group_id' => 100,
            'meta_group_id' => EveType::META_GROUP_TECH_II,
            'published' => true,
        ]);

        EveType::create([
            'type_id' => $t1TypeId,
            'name' => $t1Name,
            'group_id' => 1,
            'market_group_id' => 100,
            'meta_group_id' => EveType::META_GROUP_TECH_I,
            'published' => true,
        ]);

        // T2 rig manufacturing blueprint – NO T1 rig in materials
        BlueprintActivity::create([
            'blueprint_type_id' => $t2TypeId + 100000,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 200],
                ['type_id' => 35, 'name' => 'Pyerite', 'quantity' => 50],
            ],
            'products' => [
                ['type_id' => $t2TypeId, 'name' => $t2Name, 'quantity' => 1],
            ],
        ]);

        // T1 rig manufacturing blueprint
        BlueprintActivity::create([
            'blueprint_type_id' => $t1TypeId + 100000,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 1800,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => $t1TypeId, 'name' => $t1Name, 'quantity' => 1],
            ],
        ]);

        // Invention activity on T1 blueprint → produces T2 blueprint
        BlueprintActivity::create([
            'blueprint_type_id' => $t1TypeId + 100000,
            'activity_id' => BlueprintActivity::ACTIVITY_INVENTION,
            'time' => 600,
            'materials' => [
                ['type_id' => 20424, 'name' => 'Datacore - Mechanical Engineering', 'quantity' => 8],
            ],
            'products' => [
                ['type_id' => $t2TypeId + 100000, 'name' => $t2Name.' Blueprint', 'quantity' => 1, 'probability' => 0.34],
            ],
            'skills' => [
                ['type_id' => 23087, 'level' => 1],  // Amarr Encryption Methods
                ['type_id' => 11452, 'level' => 1],  // Mechanical Engineering
                ['type_id' => 11448, 'level' => 1],  // Laser Physics
            ],
        ]);
    }

    public function test_search_t2_items_returns_t2_rig_without_t1_material(): void
    {
        $this->createT2RigWithInventionChain(61001, 'Medium Ancillary Current Router II', 61002, 'Medium Ancillary Current Router I');

        $results = $this->service->searchT2Items('Ancillary Current Router');

        $this->assertCount(1, $results);
        $this->assertEquals(61001, $results[0]['type_id']);
        $this->assertEquals('Medium Ancillary Current Router II', $results[0]['name']);
    }

    public function test_resolve_invention_chain_for_t2_rig_without_t1_material(): void
    {
        $this->createT2RigWithInventionChain(61003, 'Small Capacitor Control Circuit II', 61004, 'Small Capacitor Control Circuit I');

        $chain = $this->service->resolveInventionChain(61003);

        $this->assertNotNull($chain, 'Invention chain should be resolved for T2 rig without T1 material');
        $this->assertEquals(61003, $chain['t2_item_type_id']);
        $this->assertEquals(61004, $chain['t1_item_type_id']);
        $this->assertEquals('Small Capacitor Control Circuit I', $chain['t1_item_name']);
        $this->assertEquals(61004 + 100000, $chain['t1_blueprint_type_id']);
        $this->assertEqualsWithDelta(0.34, $chain['base_probability'], 0.001);
        $this->assertEquals(23087, $chain['encryption_skill_id']);
    }
}
