<?php

namespace Tests\Unit;

use App\Models\BlueprintActivity;
use App\Models\BuildProgram;
use App\Models\EveType;
use App\Models\User;
use App\Services\AssetService;
use App\Services\EsiService;
use App\Services\Heavy\BuildProgramProgressCalculator;
use App\Services\InventionPlannerService;
use App\Services\ManufacturingCalculatorService;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BuildProgramTest extends TestCase
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

    private function createUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'test'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ], $attrs));
    }

    // ── resolveComponentBuildData (non-DB paths) ────────────────────

    public function test_resolve_component_build_data_handles_empty_materials(): void
    {
        $result = $this->service->resolveComponentBuildData([]);
        $this->assertEmpty($result);
    }

    public function test_resolve_component_build_data_skips_zero_type_id(): void
    {
        $t2Materials = [
            ['type_id' => 0, 'name' => 'Nothing', 'adjusted_quantity' => 10],
        ];

        $result = $this->service->resolveComponentBuildData($t2Materials);
        $this->assertEmpty($result);
    }

    public function test_resolve_component_build_data_returns_null_for_non_buildable(): void
    {
        // Material with no manufacturing blueprint should return null
        $t2Materials = [
            ['type_id' => 999999, 'name' => 'Nonexistent', 'adjusted_quantity' => 10],
        ];

        $result = $this->service->resolveComponentBuildData($t2Materials);
        $this->assertArrayHasKey(999999, $result);
        $this->assertNull($result[999999]);
    }

    public function test_resolve_component_build_data_finds_reaction_formulas(): void
    {
        // Create a reaction blueprint for Caesarium Cadmide
        BlueprintActivity::create([
            'blueprint_type_id' => 46170,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16643, 'name' => 'Cadmium', 'quantity' => 100],
                ['type_id' => 16647, 'name' => 'Caesium', 'quantity' => 100],
                ['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'quantity' => 5],
            ],
            'products' => [
                ['type_id' => 16670, 'name' => 'Caesarium Cadmide', 'quantity' => 200],
            ],
        ]);

        $t2Materials = [
            ['type_id' => 16670, 'name' => 'Caesarium Cadmide', 'adjusted_quantity' => 200],
        ];

        $result = $this->service->resolveComponentBuildData($t2Materials);

        // Should find the reaction and return build data (not null)
        $this->assertArrayHasKey(16670, $result);
        $this->assertNotNull($result[16670], 'Reaction-based materials should return build data');
        $this->assertTrue($result[16670]['is_buildable']);
        $this->assertEquals(46170, $result[16670]['blueprint_type_id']);
        $this->assertEquals(200, $result[16670]['portion_size']);
        $this->assertEquals(1, $result[16670]['runs_needed']);

        // Should include ALL reaction inputs as materials
        $this->assertCount(3, $result[16670]['materials']);
        $materialTypeIds = array_column($result[16670]['materials'], 'type_id');
        $this->assertContains(16643, $materialTypeIds, 'Cadmium should be in materials');
        $this->assertContains(16647, $materialTypeIds, 'Caesium should be in materials');
        $this->assertContains(4312, $materialTypeIds, 'Oxygen Fuel Block should be in materials');
    }

    public function test_resolve_component_build_data_prefers_manufacturing_over_reaction(): void
    {
        // Create both a manufacturing blueprint and a reaction for the same product
        BlueprintActivity::create([
            'blueprint_type_id' => 1001,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 7200,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500],
            ],
            'products' => [
                ['type_id' => 5000, 'name' => 'Test Component', 'quantity' => 1],
            ],
        ]);

        BlueprintActivity::create([
            'blueprint_type_id' => 1002,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16643, 'name' => 'Cadmium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 5000, 'name' => 'Test Component', 'quantity' => 200],
            ],
        ]);

        $t2Materials = [
            ['type_id' => 5000, 'name' => 'Test Component', 'adjusted_quantity' => 10],
        ];

        $result = $this->service->resolveComponentBuildData($t2Materials);

        // Manufacturing blueprint should be preferred
        $this->assertNotNull($result[5000]);
        $this->assertEquals(1001, $result[5000]['blueprint_type_id']);
    }

    // ── BuildProgram model ──────────────────────────────────────────

    public function test_build_program_model_casts(): void
    {
        $user = $this->createUser();

        $program = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Test Program',
            't2_type_id' => 12345,
            't2_type_name' => 'Test Item II',
            'steps' => [
                ['type_id' => 100, 'name' => 'Part A', 'quantity_required' => 10, 'materials' => []],
            ],
            'settings' => ['decryptor_key' => 'none'],
        ]);

        $this->assertIsArray($program->steps);
        $this->assertIsArray($program->settings);
        $this->assertIsInt($program->t2_type_id);
        $this->assertEquals(12345, $program->t2_type_id);
        $this->assertCount(1, $program->steps);
    }

    public function test_build_program_belongs_to_user(): void
    {
        $user = $this->createUser();

        $program = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Test Program',
            't2_type_id' => 12345,
            't2_type_name' => 'Test Item II',
            'steps' => [],
            'settings' => [],
        ]);

        $this->assertEquals($user->id, $program->user->id);
    }

    public function test_user_has_build_programs(): void
    {
        $user = $this->createUser();

        BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Program 1',
            't2_type_id' => 12345,
            't2_type_name' => 'Item II',
            'steps' => [],
            'settings' => [],
        ]);

        BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Program 2',
            't2_type_id' => 12346,
            't2_type_name' => 'Item III',
            'steps' => [],
            'settings' => [],
        ]);

        $this->assertCount(2, $user->buildPrograms);
    }

    public function test_build_program_cascade_delete_with_user(): void
    {
        $user = $this->createUser();

        BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Program',
            't2_type_id' => 12345,
            't2_type_name' => 'Item II',
            'steps' => [],
            'settings' => [],
        ]);

        $this->assertCount(1, BuildProgram::where('user_id', $user->id)->get());

        $user->delete();

        $this->assertCount(0, BuildProgram::where('user_id', $user->id)->get());
    }

    public function test_build_program_stores_complex_steps(): void
    {
        $user = $this->createUser();

        $steps = [
            [
                'type_id' => 100,
                'name' => 'Sustained Shield Emitter',
                'quantity_required' => 50,
                'runs_needed' => 50,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                    ['type_id' => 35, 'name' => 'Pyerite', 'adjusted_quantity' => 200],
                ],
                'children' => [],
            ],
            [
                'type_id' => 200,
                'name' => 'Hammerhead I',
                'quantity_required' => 10,
                'runs_needed' => 10,
                'materials' => [],
                'children' => [],
            ],
        ];

        $program = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Complex Program',
            't2_type_id' => 12345,
            't2_type_name' => 'Hammerhead II',
            'steps' => $steps,
            'settings' => ['decryptor_key' => 'accelerant', 'invention_runs' => 5],
        ]);

        $fresh = $program->fresh();
        $this->assertCount(2, $fresh->steps);
        $this->assertEquals('Sustained Shield Emitter', $fresh->steps[0]['name']);
        $this->assertCount(2, $fresh->steps[0]['materials']);
        $this->assertEquals(500, $fresh->steps[0]['materials'][0]['adjusted_quantity']);
        $this->assertEquals('accelerant', $fresh->settings['decryptor_key']);
        $this->assertArrayHasKey('children', $fresh->steps[0]);
    }

    // ── resolveFullMaterialChain (non-DB paths) ─────────────────────

    public function test_resolve_full_material_chain_handles_empty_materials(): void
    {
        $result = $this->service->resolveFullMaterialChain([]);
        $this->assertEmpty($result);
    }

    public function test_resolve_full_material_chain_skips_zero_type_id(): void
    {
        $result = $this->service->resolveFullMaterialChain([
            ['type_id' => 0, 'name' => 'Nothing', 'adjusted_quantity' => 10],
        ]);
        $this->assertEmpty($result);
    }

    public function test_resolve_full_material_chain_skips_visited_type_ids(): void
    {
        $result = $this->service->resolveFullMaterialChain(
            [['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 100]],
            10,
            0.0,
            [34] // already visited
        );
        $this->assertEmpty($result);
    }

    public function test_resolve_full_material_chain_returns_empty_for_raw_materials(): void
    {
        // Tritanium (34) has no manufacturing blueprint in SQLite test DB
        $result = $this->service->resolveFullMaterialChain([
            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 100],
        ]);
        $this->assertEmpty($result);
    }

    // ── Build program name update ──────────────────────────────────

    public function test_build_program_name_can_be_updated(): void
    {
        $user = $this->createUser();

        $program = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Original Name',
            't2_type_id' => 12345,
            't2_type_name' => 'Test Item II',
            'steps' => [],
            'settings' => [],
        ]);

        $program->update(['name' => 'New Name']);
        $this->assertEquals('New Name', $program->fresh()->name);
    }

    public function test_build_program_stores_activity_type_in_steps(): void
    {
        $user = $this->createUser();

        $steps = [
            [
                'type_id' => 100,
                'name' => 'Manufactured Component',
                'quantity_required' => 50,
                'runs_needed' => 50,
                'activity_type' => 'manufacturing',
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                ],
                'children' => [],
            ],
            [
                'type_id' => 200,
                'name' => 'Reaction Product',
                'quantity_required' => 10,
                'runs_needed' => 1,
                'activity_type' => 'reaction',
                'materials' => [
                    ['type_id' => 35, 'name' => 'Pyerite', 'adjusted_quantity' => 200],
                ],
                'children' => [],
            ],
        ];

        $program = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Full Chain Program',
            't2_type_id' => 12345,
            't2_type_name' => 'Test Item II',
            'steps' => $steps,
            'settings' => [],
        ]);

        $fresh = $program->fresh();
        $this->assertCount(2, $fresh->steps);
        $this->assertEquals('manufacturing', $fresh->steps[0]['activity_type']);
        $this->assertEquals('reaction', $fresh->steps[1]['activity_type']);
    }

    public function test_build_program_stores_nested_children(): void
    {
        $user = $this->createUser();

        $steps = [
            [
                'type_id' => 100,
                'name' => 'T2 Component',
                'quantity_required' => 10,
                'runs_needed' => 10,
                'activity_type' => 'manufacturing',
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                    ['type_id' => 200, 'name' => 'Sub Component', 'adjusted_quantity' => 20],
                ],
                'children' => [
                    [
                        'type_id' => 200,
                        'name' => 'Sub Component',
                        'quantity_required' => 20,
                        'runs_needed' => 20,
                        'activity_type' => 'manufacturing',
                        'materials' => [
                            ['type_id' => 35, 'name' => 'Pyerite', 'adjusted_quantity' => 100],
                        ],
                        'children' => [],
                    ],
                ],
            ],
        ];

        $program = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Nested Program',
            't2_type_id' => 12345,
            't2_type_name' => 'Test Item II',
            'steps' => $steps,
            'settings' => [],
        ]);

        $fresh = $program->fresh();
        $this->assertCount(1, $fresh->steps);
        $this->assertArrayHasKey('children', $fresh->steps[0]);
        $this->assertCount(1, $fresh->steps[0]['children']);
        $this->assertEquals('Sub Component', $fresh->steps[0]['children'][0]['name']);
        $this->assertEquals(20, $fresh->steps[0]['children'][0]['quantity_required']);
        $this->assertEmpty($fresh->steps[0]['children'][0]['children']);
    }

    // ── resolveFullMaterialChain with reaction blueprints ────────────

    public function test_resolve_full_material_chain_includes_all_reaction_materials(): void
    {
        // Create a reaction blueprint for "Caesarium Cadmide" with ALL inputs:
        // Cadmium (raw mineral), Caesium (raw mineral), Oxygen Fuel Block (manufactured)
        BlueprintActivity::create([
            'blueprint_type_id' => 46170,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16643, 'name' => 'Cadmium', 'quantity' => 100],
                ['type_id' => 16647, 'name' => 'Caesium', 'quantity' => 100],
                ['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'quantity' => 5],
            ],
            'products' => [
                ['type_id' => 16670, 'name' => 'Caesarium Cadmide', 'quantity' => 200],
            ],
        ]);

        $result = $this->service->resolveFullMaterialChain([
            ['type_id' => 16670, 'name' => 'Caesarium Cadmide', 'adjusted_quantity' => 200],
        ]);

        // Should create a step for Caesarium Cadmide
        $this->assertCount(1, $result);
        $step = $result[0];
        $this->assertEquals(16670, $step['type_id']);
        $this->assertEquals('reaction', $step['activity_type']);
        $this->assertEquals(200, $step['quantity_required']);
        $this->assertEquals(1, $step['runs_needed']);

        // The step's materials should include ALL three reaction inputs
        $this->assertCount(3, $step['materials'], 'Reaction step should include all 3 input materials');

        $materialTypeIds = array_column($step['materials'], 'type_id');
        $this->assertContains(16643, $materialTypeIds, 'Cadmium should be in materials');
        $this->assertContains(16647, $materialTypeIds, 'Caesium should be in materials');
        $this->assertContains(4312, $materialTypeIds, 'Oxygen Fuel Block should be in materials');

        // All materials should have adjusted_quantity set
        foreach ($step['materials'] as $mat) {
            $this->assertArrayHasKey('adjusted_quantity', $mat);
            $this->assertGreaterThan(0, $mat['adjusted_quantity']);
        }
    }

    public function test_resolve_full_material_chain_skips_fuel_blocks(): void
    {
        // Create a reaction for Caesarium Cadmide
        BlueprintActivity::create([
            'blueprint_type_id' => 46170,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16643, 'name' => 'Cadmium', 'quantity' => 100],
                ['type_id' => 16647, 'name' => 'Caesium', 'quantity' => 100],
                ['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'quantity' => 5],
            ],
            'products' => [
                ['type_id' => 16670, 'name' => 'Caesarium Cadmide', 'quantity' => 200],
            ],
        ]);

        // Create a manufacturing blueprint for Oxygen Fuel Block
        BlueprintActivity::create([
            'blueprint_type_id' => 4313,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 7200,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500],
            ],
            'products' => [
                ['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'quantity' => 40],
            ],
        ]);

        // Create EveType for Oxygen Fuel Block so resolveFullMaterialChain can identify it
        EveType::create(['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'group_id' => AssetService::FUEL_BLOCK_GROUP_ID, 'published' => true]);

        $result = $this->service->resolveFullMaterialChain([
            ['type_id' => 16670, 'name' => 'Caesarium Cadmide', 'adjusted_quantity' => 200],
        ]);

        // Caesarium Cadmide step
        $this->assertCount(1, $result);
        $ccStep = $result[0];
        $this->assertEquals('reaction', $ccStep['activity_type']);

        // Materials should include ALL 3 reaction inputs
        $this->assertCount(3, $ccStep['materials'], 'All 3 reaction inputs should be in materials');

        // Fuel block should NOT be resolved as a child step (treated as raw material)
        $this->assertEmpty($ccStep['children'], 'Fuel blocks should not be resolved as child steps');
    }

    public function test_resolve_full_material_chain_includes_blueprint_type_id(): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => 46170,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 16643, 'name' => 'Cadmium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 16670, 'name' => 'Caesarium Cadmide', 'quantity' => 200],
            ],
        ]);

        $result = $this->service->resolveFullMaterialChain([
            ['type_id' => 16670, 'name' => 'Caesarium Cadmide', 'adjusted_quantity' => 200],
        ]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('blueprint_type_id', $result[0]);
        $this->assertEquals(46170, $result[0]['blueprint_type_id']);
    }

    // ── collectSummaryData ──────────────────────────────────────────

    public function test_collect_summary_data_finds_blueprints_and_base_materials(): void
    {
        // Simulate a stored build program with nested steps
        $steps = [
            [
                'type_id' => 11399,
                'name' => 'Morphite',
                'quantity_required' => 100,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 11400,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                    ['type_id' => 35, 'name' => 'Pyerite', 'adjusted_quantity' => 200],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [11400 => 1, 34 => 100, 35 => 0];

        // Use reflection to call private method
        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $method->invokeArgs($controller, $args);

        // Should find the blueprint and mark it as owned
        $this->assertCount(1, $blueprints);
        $this->assertArrayHasKey(11400, $blueprints);
        $this->assertTrue($blueprints[11400]['owned']);
        $this->assertEquals('manufacturing', $blueprints[11400]['activity_type']);

        // Should find both base materials (no children for either)
        $this->assertCount(2, $baseMaterials);
        $this->assertArrayHasKey(34, $baseMaterials);
        $this->assertEquals(500, $baseMaterials[34]['quantity']);
        $this->assertEquals(100, $baseMaterials[34]['available']);
        $this->assertArrayHasKey(35, $baseMaterials);
        $this->assertEquals(200, $baseMaterials[35]['quantity']);
        $this->assertEquals(0, $baseMaterials[35]['available']);
    }

    public function test_collect_summary_data_separates_fuel_blocks_from_base_materials(): void
    {
        // Step where Oxygen Fuel Block (4312) has a child step (can be manufactured)
        $steps = [
            [
                'type_id' => 16670,
                'name' => 'Caesarium Cadmide',
                'quantity_required' => 200,
                'activity_type' => 'reaction',
                'blueprint_type_id' => 46170,
                'materials' => [
                    ['type_id' => 16643, 'name' => 'Cadmium', 'adjusted_quantity' => 100],
                    ['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'adjusted_quantity' => 5],
                ],
                'children' => [
                    [
                        'type_id' => 4312,
                        'name' => 'Oxygen Fuel Block',
                        'quantity_required' => 5,
                        'activity_type' => 'manufacturing',
                        'blueprint_type_id' => 4313,
                        'materials' => [
                            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                        ],
                        'children' => [],
                    ],
                ],
            ],
        ];

        $assetQuantities = [];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        // Mark 4312 as a fuel block via typeGroupMap
        $typeGroupMap = [4312 => 1136];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $method->invokeArgs($controller, $args);

        // Should find only the reaction formula blueprint (fuel block child is skipped)
        $this->assertCount(1, $blueprints);
        $this->assertArrayHasKey(46170, $blueprints);
        $this->assertEquals('reaction', $blueprints[46170]['activity_type']);

        // Oxygen Fuel Block should be in fuel blocks, NOT in base materials
        $this->assertArrayNotHasKey(4312, $baseMaterials);
        $this->assertArrayHasKey(4312, $fuelBlocks);
        $this->assertEquals(5, $fuelBlocks[4312]['quantity']);
        $this->assertEquals('Fuel Blocks', $fuelBlocks[4312]['group_name']);

        // Cadmium should be a base material
        $this->assertArrayHasKey(16643, $baseMaterials);
        $this->assertEquals(100, $baseMaterials[16643]['quantity']);

        // Tritanium (fuel block sub-material) should NOT be in base materials
        $this->assertArrayNotHasKey(34, $baseMaterials);
    }

    public function test_collect_summary_data_aggregates_same_base_material(): void
    {
        // Two steps that both need Tritanium
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                ],
                'children' => [],
            ],
            [
                'type_id' => 200,
                'name' => 'Component B',
                'quantity_required' => 5,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 201,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 300],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [34 => 1000];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $method->invokeArgs($controller, $args);

        // Tritanium should be aggregated: 500 + 300 = 800
        $this->assertArrayHasKey(34, $baseMaterials);
        $this->assertEquals(800, $baseMaterials[34]['quantity']);
        $this->assertEquals(1000, $baseMaterials[34]['available']);
    }

    // ── Fuel block separation & group names in progress response ────

    public function test_progress_separates_fuel_blocks_from_base_materials(): void
    {
        $user = $this->createUser();

        // Create EveType records for Tritanium (group 18 = Minerals)
        // and an Oxygen Fuel Block (group 1136 = Fuel Blocks)
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'group_id' => 18, 'published' => true]);
        EveType::create(['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'group_id' => 1136, 'published' => true]);

        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                    ['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'adjusted_quantity' => 20],
                ],
                'children' => [],
            ],
        ];

        $program = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Fuel Block Test',
            't2_type_id' => 12345,
            't2_type_name' => 'Test Item II',
            'steps' => $steps,
            'settings' => [],
        ]);

        $controller = new BuildProgramProgressCalculator;
        $collectMethod = new \ReflectionMethod($controller, 'collectSummaryData');
        $collectMethod->setAccessible(true);

        $assetQuantities = [34 => 100, 4312 => 5];
        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [34 => 18, 4312 => AssetService::FUEL_BLOCK_GROUP_ID];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $collectMethod->invokeArgs($controller, $args);

        // Tritanium should be in base materials
        $this->assertArrayHasKey(34, $baseMaterials);
        $this->assertEquals(500, $baseMaterials[34]['quantity']);

        // Oxygen Fuel Block should be in fuel blocks, not base materials
        $this->assertArrayNotHasKey(4312, $baseMaterials);
        $this->assertArrayHasKey(4312, $fuelBlocks);
        $this->assertEquals('Fuel Blocks', $fuelBlocks[4312]['group_name']);
        $this->assertEquals(20, $fuelBlocks[4312]['quantity']);
    }

    public function test_base_materials_get_group_name_other_for_unknown_groups(): void
    {
        // Material with no EveType entry should get group_name 'Other'
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 999888, 'name' => 'Unknown Material', 'adjusted_quantity' => 50],
                ],
                'children' => [],
            ],
        ];

        $controller = new BuildProgramProgressCalculator;
        $collectMethod = new \ReflectionMethod($controller, 'collectSummaryData');
        $collectMethod->setAccessible(true);

        $assetQuantities = [];
        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $collectMethod->invokeArgs($controller, $args);

        // Resolve group names (mirrors progress method logic)
        $matGroupIds = EveType::whereIn('type_id', array_keys($baseMaterials))
            ->pluck('group_id', 'type_id')
            ->all();
        foreach ($baseMaterials as $matTypeId => &$mat) {
            $groupId = $matGroupIds[$matTypeId] ?? null;
            $mat['group_name'] = ($groupId !== null && isset(AssetService::MATERIAL_GROUPS[$groupId]))
                ? AssetService::MATERIAL_GROUPS[$groupId]
                : 'Other';
        }
        unset($mat);

        $this->assertArrayHasKey(999888, $baseMaterials);
        $this->assertEquals('Other', $baseMaterials[999888]['group_name']);
    }

    public function test_collect_summary_data_includes_raw_material_steps_as_base_materials(): void
    {
        // Steps that have no blueprint_type_id are raw materials (e.g. minerals)
        // exported directly from the manufacturing calculator. They should appear
        // in the base materials to acquire list.
        $steps = [
            [
                'type_id' => 34,
                'name' => 'Tritanium',
                'quantity_required' => 500,
                'materials' => [],
                'children' => [],
            ],
            [
                'type_id' => 35,
                'name' => 'Pyerite',
                'quantity_required' => 300,
                'materials' => [],
                'children' => [],
            ],
            [
                'type_id' => 100,
                'name' => 'T2 Component',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 36, 'name' => 'Mexallon', 'adjusted_quantity' => 200],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [34 => 100];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $method->invokeArgs($controller, $args);

        // Raw material steps should be in base materials
        $this->assertArrayHasKey(34, $baseMaterials);
        $this->assertEquals(500, $baseMaterials[34]['quantity']);
        $this->assertEquals('Tritanium', $baseMaterials[34]['name']);
        $this->assertEquals(100, $baseMaterials[34]['available']);

        $this->assertArrayHasKey(35, $baseMaterials);
        $this->assertEquals(300, $baseMaterials[35]['quantity']);
        $this->assertEquals(0, $baseMaterials[35]['available']);

        // Regular material from the manufactured step should also be there
        $this->assertArrayHasKey(36, $baseMaterials);
        $this->assertEquals(200, $baseMaterials[36]['quantity']);

        // Only the manufactured step should have a blueprint
        $this->assertCount(1, $blueprints);
        $this->assertArrayHasKey(101, $blueprints);
    }

    public function test_collect_summary_data_aggregates_raw_material_step_with_same_material_in_children(): void
    {
        // Tritanium appears both as a raw material step AND as a material
        // within another step's materials — quantities should be aggregated.
        $steps = [
            [
                'type_id' => 34,
                'name' => 'Tritanium',
                'quantity_required' => 500,
                'materials' => [],
                'children' => [],
            ],
            [
                'type_id' => 100,
                'name' => 'T2 Component',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 300],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $method->invokeArgs($controller, $args);

        // Tritanium should be aggregated: 500 (from step) + 300 (from material) = 800
        $this->assertArrayHasKey(34, $baseMaterials);
        $this->assertEquals(800, $baseMaterials[34]['quantity']);
    }

    // ── Build program merge ────────────────────────────────────────

    public function test_merge_combines_steps_from_multiple_programs(): void
    {
        $user = $this->createUser();

        $programA = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Program A',
            't2_type_id' => 100,
            't2_type_name' => 'Item A',
            'steps' => [
                ['type_id' => 100, 'name' => 'Step A1', 'quantity_required' => 5, 'runs_needed' => 5, 'activity_type' => 'manufacturing', 'materials' => [], 'children' => []],
            ],
            'settings' => ['me_level' => 10],
        ]);

        $programB = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Program B',
            't2_type_id' => 200,
            't2_type_name' => 'Item B',
            'steps' => [
                ['type_id' => 200, 'name' => 'Step B1', 'quantity_required' => 3, 'runs_needed' => 3, 'activity_type' => 'manufacturing', 'materials' => [], 'children' => []],
                ['type_id' => 201, 'name' => 'Step B2', 'quantity_required' => 7, 'runs_needed' => 7, 'activity_type' => 'manufacturing', 'materials' => [], 'children' => []],
            ],
            'settings' => ['te_level' => 20],
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$programA->id, $programB->id],
            'name' => 'Merged Program',
        ]);

        $response->assertStatus(201);

        $merged = BuildProgram::find($response->json('data.id'));
        $this->assertNotNull($merged);
        $this->assertEquals('Merged Program', $merged->name);
        $this->assertCount(3, $merged->steps);
        $this->assertEquals('Step A1', $merged->steps[0]['name']);
        $this->assertEquals('Step B1', $merged->steps[1]['name']);
        $this->assertEquals('Step B2', $merged->steps[2]['name']);

        // Source programs should remain unchanged
        $this->assertNotNull($programA->fresh());
        $this->assertNotNull($programB->fresh());
    }

    public function test_merge_stores_sections_metadata(): void
    {
        $user = $this->createUser();

        $programA = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Ishtar Build',
            'steps' => [
                ['type_id' => 100, 'name' => 'Step A1', 'quantity_required' => 5, 'runs_needed' => 5, 'materials' => [], 'children' => []],
            ],
            'settings' => [],
        ]);

        $programB = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Gila Build',
            'steps' => [
                ['type_id' => 200, 'name' => 'Step B1', 'quantity_required' => 3, 'runs_needed' => 3, 'materials' => [], 'children' => []],
                ['type_id' => 201, 'name' => 'Step B2', 'quantity_required' => 7, 'runs_needed' => 7, 'materials' => [], 'children' => []],
            ],
            'settings' => [],
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$programA->id, $programB->id],
            'name' => 'Combined',
        ]);

        $response->assertStatus(201);
        $merged = BuildProgram::find($response->json('data.id'));

        $sections = $merged->settings['sections'] ?? [];
        $this->assertCount(2, $sections);
        $this->assertEquals('Ishtar Build', $sections[0]['name']);
        $this->assertEquals(1, $sections[0]['step_count']);
        $this->assertEquals('Gila Build', $sections[1]['name']);
        $this->assertEquals(2, $sections[1]['step_count']);
    }

    public function test_merge_preserves_section_order_matching_program_ids(): void
    {
        $user = $this->createUser();

        $programA = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'First',
            'steps' => [
                ['type_id' => 100, 'name' => 'A', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []],
            ],
            'settings' => [],
        ]);

        $programB = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Second',
            'steps' => [
                ['type_id' => 200, 'name' => 'B', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []],
            ],
            'settings' => [],
        ]);

        $this->actingAs($user);

        // Provide IDs in reverse order (B first, A second)
        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$programB->id, $programA->id],
            'name' => 'Reversed',
        ]);

        $response->assertStatus(201);
        $merged = BuildProgram::find($response->json('data.id'));

        $sections = $merged->settings['sections'] ?? [];
        $this->assertEquals('Second', $sections[0]['name']);
        $this->assertEquals('First', $sections[1]['name']);

        // Steps should also follow the provided order
        $this->assertEquals('B', $merged->steps[0]['name']);
        $this->assertEquals('A', $merged->steps[1]['name']);
    }

    public function test_merge_requires_at_least_two_program_ids(): void
    {
        $user = $this->createUser();

        $program = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Solo Program',
            'steps' => [['type_id' => 100, 'name' => 'Step', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$program->id],
            'name' => 'Merged',
        ]);

        $response->assertStatus(422);
    }

    public function test_merge_rejects_programs_not_owned_by_user(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();

        $ownProgram = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Own',
            'steps' => [['type_id' => 100, 'name' => 'A', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $otherProgram = BuildProgram::create([
            'user_id' => $other->id,
            'name' => 'Other',
            'steps' => [['type_id' => 200, 'name' => 'B', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$ownProgram->id, $otherProgram->id],
            'name' => 'Merged',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['program_ids']);
    }

    public function test_merge_sets_manufacturing_type_for_mixed_program_types(): void
    {
        $user = $this->createUser();

        $mfg = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Mfg',
            'program_type' => 'manufacturing',
            'steps' => [['type_id' => 100, 'name' => 'A', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $rxn = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Rxn',
            'program_type' => 'reaction',
            'steps' => [['type_id' => 200, 'name' => 'B', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$mfg->id, $rxn->id],
            'name' => 'Mixed Merge',
        ]);

        $response->assertStatus(201);
        $merged = BuildProgram::find($response->json('data.id'));
        $this->assertEquals('manufacturing', $merged->program_type);
    }

    public function test_merge_preserves_program_type_when_same(): void
    {
        $user = $this->createUser();

        $rxn1 = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Rxn1',
            'program_type' => 'reaction',
            'steps' => [['type_id' => 100, 'name' => 'A', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $rxn2 = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'Rxn2',
            'program_type' => 'reaction',
            'steps' => [['type_id' => 200, 'name' => 'B', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$rxn1->id, $rxn2->id],
            'name' => 'Combined Reactions',
        ]);

        $response->assertStatus(201);
        $merged = BuildProgram::find($response->json('data.id'));
        $this->assertEquals('reaction', $merged->program_type);
    }

    public function test_merge_requires_name(): void
    {
        $user = $this->createUser();

        $a = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'A',
            'steps' => [['type_id' => 100, 'name' => 'X', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $b = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'B',
            'steps' => [['type_id' => 200, 'name' => 'Y', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$a->id, $b->id],
        ]);

        $response->assertStatus(422);
    }

    public function test_merge_sets_null_type_id_and_type_name(): void
    {
        $user = $this->createUser();

        $a = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'A',
            't2_type_id' => 100,
            't2_type_name' => 'Item A',
            'steps' => [['type_id' => 100, 'name' => 'X', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $b = BuildProgram::create([
            'user_id' => $user->id,
            'name' => 'B',
            't2_type_id' => 200,
            't2_type_name' => 'Item B',
            'steps' => [['type_id' => 200, 'name' => 'Y', 'quantity_required' => 1, 'runs_needed' => 1, 'materials' => [], 'children' => []]],
            'settings' => [],
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('api.v1.programs.build-programs.merge'), [
            'program_ids' => [$a->id, $b->id],
            'name' => 'Merged',
        ]);

        $response->assertStatus(201);
        $merged = BuildProgram::find($response->json('data.id'));
        $this->assertNull($merged->t2_type_id);
        $this->assertNull($merged->t2_type_name);
    }

    // ── Completed items reduction ─────────────────────────────────────

    public function test_progress_reduces_materials_when_user_has_completed_items(): void
    {
        // Step: Make 10 of Component A, requiring 1000 Tritanium.
        // User has 3 of Component A → only 7 need to be built.
        // Material requirement should scale to ceil(1000 * 7/10) = 700.
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 1000],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [100 => 3, 34 => 500];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'calculateNestedProgress');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $steps, $assetQuantities, $assetQuantities);

        // Step should show original 10 required, 3 available
        $step = $result['steps'][0];
        $this->assertEquals(10, $step['quantity_required']);
        $this->assertEquals(3, $step['available']);
        $this->assertEquals(30.0, $step['progress']);

        // Material should be reduced: ceil(1000 * 7/10) = 700
        $mat = $step['materials'][0];
        $this->assertEquals(700, $mat['required']);
        $this->assertEquals(500, $mat['available']);
    }

    public function test_progress_reduces_child_steps_when_parent_has_completed_items(): void
    {
        // Parent step: Make 10 of T2 Item, user has 4 → needs 6 more.
        // Child step: Intermediate × 50 (for all 10).
        // Scaled child: ceil(50 * 6/10) = 30.
        $steps = [
            [
                'type_id' => 100,
                'name' => 'T2 Item',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 200, 'name' => 'Intermediate', 'adjusted_quantity' => 50],
                ],
                'children' => [
                    [
                        'type_id' => 200,
                        'name' => 'Intermediate',
                        'quantity_required' => 50,
                        'activity_type' => 'manufacturing',
                        'blueprint_type_id' => 201,
                        'materials' => [
                            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                        ],
                        'children' => [],
                    ],
                ],
            ],
        ];

        $assetQuantities = [100 => 4, 200 => 10, 34 => 100];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'calculateNestedProgress');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $steps, $assetQuantities, $assetQuantities);

        // Parent step: 10 required, 4 available
        $parent = $result['steps'][0];
        $this->assertEquals(10, $parent['quantity_required']);
        $this->assertEquals(4, $parent['available']);

        // Child step should be scaled: ceil(50 * 6/10) = 30
        $child = $parent['children'][0];
        $this->assertEquals(30, $child['quantity_required']);
        $this->assertEquals(10, $child['available']);

        // Child's material should also be scaled:
        // remaining child = 30 - 10 = 20, childScale = 20/50
        // material = ceil(500 * 20/50) = 200
        $childMat = $child['materials'][0];
        $this->assertEquals(200, $childMat['required']);
    }

    public function test_progress_sets_zero_materials_when_step_fully_completed(): void
    {
        // User has all 10 items → remaining = 0 → materials = 0.
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 1000],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [100 => 10, 34 => 500];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'calculateNestedProgress');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $steps, $assetQuantities, $assetQuantities);

        $step = $result['steps'][0];
        $this->assertTrue($step['has_enough']);
        $this->assertEquals(100.0, $step['progress']);

        // Materials should be 0 since nothing needs to be built
        $mat = $step['materials'][0];
        $this->assertEquals(0, $mat['required']);
        $this->assertTrue($mat['has_enough']);
    }

    public function test_progress_no_reduction_when_no_completed_items(): void
    {
        // User has 0 completed items → materials unchanged.
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 1000],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [34 => 200];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'calculateNestedProgress');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $steps, $assetQuantities, $assetQuantities);

        $mat = $result['steps'][0]['materials'][0];
        $this->assertEquals(1000, $mat['required']);
        $this->assertEquals(200, $mat['available']);
    }

    public function test_summary_reduces_base_materials_when_step_has_completed_items(): void
    {
        // Step needs 10 of Component A with 500 Tritanium.
        // User has 3 of Component A → materials scale to ceil(500 * 7/10) = 350.
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [100 => 3, 34 => 100];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $method->invokeArgs($controller, $args);

        $this->assertArrayHasKey(34, $baseMaterials);
        $this->assertEquals(350, $baseMaterials[34]['quantity']);
    }

    public function test_summary_reduces_fuel_blocks_when_step_has_completed_items(): void
    {
        // Step needs 10 of Component A with 20 fuel blocks.
        // User has 5 → scale = 5/10 → ceil(20 * 5/10) = 10.
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 4312, 'name' => 'Oxygen Fuel Block', 'adjusted_quantity' => 20],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [100 => 5];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [4312 => AssetService::FUEL_BLOCK_GROUP_ID];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $method->invokeArgs($controller, $args);

        $this->assertArrayHasKey(4312, $fuelBlocks);
        $this->assertEquals(10, $fuelBlocks[4312]['quantity']);
    }

    public function test_summary_zero_materials_when_step_fully_completed(): void
    {
        // User has all 10 → materials should be 0 → not added to summary.
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 500],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [100 => 10, 34 => 100];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap];
        $method->invokeArgs($controller, $args);

        // ceil(500 * 0/10) = 0, so material is added with quantity 0
        $this->assertArrayHasKey(34, $baseMaterials);
        $this->assertEquals(0, $baseMaterials[34]['quantity']);
    }

    public function test_summary_reduces_raw_material_steps_when_parent_scaled(): void
    {
        // Raw material step (no blueprint) with parent scaling.
        // For top-level raw material steps, parentScale is 1.0 so they are unaffected.
        // But a raw material step nested as a child would receive parent scaling.
        $steps = [
            [
                'type_id' => 34,
                'name' => 'Tritanium',
                'quantity_required' => 500,
                'materials' => [],
                'children' => [],
            ],
        ];

        $assetQuantities = [];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'collectSummaryData');
        $method->setAccessible(true);

        // Call with parentScale = 0.7 (simulating parent has 30% complete)
        $blueprints = [];
        $baseMaterials = [];
        $fuelBlocks = [];
        $typeGroupMap = [];
        $args = [$steps, $assetQuantities, $assetQuantities, &$blueprints, &$baseMaterials, &$fuelBlocks, $typeGroupMap, 0.7];
        $method->invokeArgs($controller, $args);

        // ceil(500 * 0.7) = 350
        $this->assertArrayHasKey(34, $baseMaterials);
        $this->assertEquals(350, $baseMaterials[34]['quantity']);
    }

    public function test_progress_cascading_reduction_through_three_levels(): void
    {
        // Three-level tree:
        // L1: T2 Item × 10, user has 4 → need 6, childScale = 6/10 = 0.6
        // L2: Intermediate × 50, scaled to ceil(50*0.6)=30, user has 10 → need 20, childScale = 20/50 = 0.4
        // L3: Moon Goo × 500, scaled to ceil(500*0.4)=200, user has 0
        $steps = [
            [
                'type_id' => 100,
                'name' => 'T2 Item',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 200, 'name' => 'Intermediate', 'adjusted_quantity' => 50],
                ],
                'children' => [
                    [
                        'type_id' => 200,
                        'name' => 'Intermediate',
                        'quantity_required' => 50,
                        'activity_type' => 'manufacturing',
                        'blueprint_type_id' => 201,
                        'materials' => [
                            ['type_id' => 300, 'name' => 'Moon Goo', 'adjusted_quantity' => 500],
                        ],
                        'children' => [
                            [
                                'type_id' => 300,
                                'name' => 'Moon Goo',
                                'quantity_required' => 500,
                                'materials' => [],
                                'children' => [],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $assetQuantities = [100 => 4, 200 => 10, 300 => 50];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'calculateNestedProgress');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $steps, $assetQuantities, $assetQuantities);

        $l1 = $result['steps'][0];
        $this->assertEquals(10, $l1['quantity_required']);
        $this->assertEquals(4, $l1['available']);

        $l2 = $l1['children'][0];
        $this->assertEquals(30, $l2['quantity_required']);
        $this->assertEquals(10, $l2['available']);

        // L2 material (Moon Goo): childScale for L2 = (30-10)/50 = 20/50 = 0.4
        $l2Mat = $l2['materials'][0];
        $this->assertEquals(200, $l2Mat['required']);

        // L3 step (Moon Goo raw): parentScale = 0.4, ceil(500*0.4) = 200
        $l3 = $l2['children'][0];
        $this->assertEquals(200, $l3['quantity_required']);
        $this->assertEquals(50, $l3['available']);
    }

    public function test_progress_with_excess_completed_items(): void
    {
        // User has MORE than required → remaining = 0, materials = 0.
        $steps = [
            [
                'type_id' => 100,
                'name' => 'Component A',
                'quantity_required' => 10,
                'activity_type' => 'manufacturing',
                'blueprint_type_id' => 101,
                'materials' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 1000],
                ],
                'children' => [],
            ],
        ];

        $assetQuantities = [100 => 15, 34 => 500];

        $controller = new BuildProgramProgressCalculator;
        $method = new \ReflectionMethod($controller, 'calculateNestedProgress');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $steps, $assetQuantities, $assetQuantities);

        $step = $result['steps'][0];
        $this->assertTrue($step['has_enough']);
        $this->assertEquals(100.0, $step['progress']);

        $mat = $step['materials'][0];
        $this->assertEquals(0, $mat['required']);
        $this->assertTrue($mat['has_enough']);
    }
}
