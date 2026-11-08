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

class ManufacturingControllerBuildDataTest extends TestCase
{
    use RefreshDatabase;

    private InventionPlannerService $planner;

    private ManufacturingCalculatorService $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $esi = Mockery::mock(EsiService::class);
        $market = Mockery::mock(MarketService::class);
        $this->calculator = new ManufacturingCalculatorService($esi, $market);
        $this->planner = new InventionPlannerService($esi, $this->calculator, $market);
    }

    public function test_resolve_component_build_data_returns_null_for_base_materials(): void
    {
        // Tritanium has no manufacturing blueprint, so it should return null
        $materials = [
            ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 1000],
        ];

        $result = $this->planner->resolveComponentBuildData($materials);

        $this->assertArrayHasKey(34, $result);
        $this->assertNull($result[34]);
    }

    public function test_resolve_component_build_data_returns_build_data_for_manufactured_item(): void
    {
        // Create a blueprint activity for a manufactured component
        BlueprintActivity::create([
            'blueprint_type_id' => 99901,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
                ['type_id' => 35, 'name' => 'Pyerite', 'quantity' => 50],
            ],
            'products' => [
                ['type_id' => 88001, 'name' => 'Test Component', 'quantity' => 1],
            ],
        ]);

        $materials = [
            ['type_id' => 88001, 'name' => 'Test Component', 'adjusted_quantity' => 5],
        ];

        $result = $this->planner->resolveComponentBuildData($materials, 10, 0.0);

        $this->assertArrayHasKey(88001, $result);
        $this->assertNotNull($result[88001]);
        $this->assertTrue($result[88001]['is_buildable']);
        $this->assertEquals(99901, $result[88001]['blueprint_type_id']);
        $this->assertEquals(5, $result[88001]['runs_needed']);
        $this->assertCount(2, $result[88001]['materials']);
    }

    public function test_resolve_component_build_data_calculates_runs_with_portion_size(): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => 99902,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 200],
            ],
            'products' => [
                ['type_id' => 88002, 'name' => 'Batch Component', 'quantity' => 10],
            ],
        ]);

        $materials = [
            ['type_id' => 88002, 'name' => 'Batch Component', 'adjusted_quantity' => 25],
        ];

        $result = $this->planner->resolveComponentBuildData($materials, 0, 0.0);

        // 25 needed, portion size 10 → ceil(25/10) = 3 runs
        $this->assertEquals(3, $result[88002]['runs_needed']);
        $this->assertEquals(10, $result[88002]['portion_size']);
    }

    public function test_resolve_component_build_data_applies_me_to_sub_materials(): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => 99903,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 88003, 'name' => 'ME Component', 'quantity' => 1],
            ],
        ]);

        $materials = [
            ['type_id' => 88003, 'name' => 'ME Component', 'adjusted_quantity' => 1],
        ];

        // ME10: 100 * 1 * 0.90 = 90
        $result = $this->planner->resolveComponentBuildData($materials, 10, 0.0);

        $this->assertEquals(90, $result[88003]['materials'][0]['adjusted_quantity']);
    }

    public function test_resolve_component_build_data_uses_separate_reaction_me_bonus(): void
    {
        // Create a reaction formula
        BlueprintActivity::create([
            'blueprint_type_id' => 99904,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Fullerite-C50', 'quantity' => 100],
                ['type_id' => 35, 'name' => 'Fullerite-C60', 'quantity' => 200],
            ],
            'products' => [
                ['type_id' => 88004, 'name' => 'Reaction Material', 'quantity' => 1],
            ],
        ]);

        // Create a manufactured component
        BlueprintActivity::create([
            'blueprint_type_id' => 99905,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 88005, 'name' => 'Manufactured Component', 'quantity' => 1],
            ],
        ]);

        $materials = [
            ['type_id' => 88004, 'name' => 'Reaction Material', 'adjusted_quantity' => 1],
            ['type_id' => 88005, 'name' => 'Manufactured Component', 'adjusted_quantity' => 1],
        ];

        $mfgMeBonus = 5.0; // 5% manufacturing structure bonus
        $reactionMeBonus = 2.0; // 2% reaction structure bonus

        $result = $this->planner->resolveComponentBuildData($materials, 10, $mfgMeBonus, $reactionMeBonus);

        // Reaction: uses ME0 (reactions always have ME0) with 2% reaction bonus: 100 * 1 * 0.98 = 98, 200 * 1 * 0.98 = 196
        $this->assertTrue($result[88004]['is_reaction']);
        $this->assertEquals(98, $result[88004]['materials'][0]['adjusted_quantity']);
        $this->assertEquals(196, $result[88004]['materials'][1]['adjusted_quantity']);

        // Manufacturing: ME10 with 5% mfg bonus: 100 * 1 * 0.90 * 0.95 = 85.5 → 86
        $this->assertFalse($result[88005]['is_reaction']);
        $this->assertEquals(86, $result[88005]['materials'][0]['adjusted_quantity']);
    }

    public function test_resolve_component_build_data_reaction_uses_mfg_bonus_when_reaction_bonus_null(): void
    {
        // Create a reaction formula
        BlueprintActivity::create([
            'blueprint_type_id' => 99906,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Fullerite-C50', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 88006, 'name' => 'Reaction Material', 'quantity' => 1],
            ],
        ]);

        $materials = [
            ['type_id' => 88006, 'name' => 'Reaction Material', 'adjusted_quantity' => 1],
        ];

        $mfgMeBonus = 5.0;

        // When reactionMeBonus is null, it should fall back to mfgMeBonus
        $result = $this->planner->resolveComponentBuildData($materials, 10, $mfgMeBonus, null);

        // Reaction: ME0 with 5% bonus (fallback): 100 * 1 * 0.95 = 95
        $this->assertEquals(95, $result[88006]['materials'][0]['adjusted_quantity']);
    }

    public function test_resolve_component_build_data_includes_is_reaction_flag(): void
    {
        // Create a reaction formula
        BlueprintActivity::create([
            'blueprint_type_id' => 99907,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Fullerite-C50', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 88007, 'name' => 'Reaction Material', 'quantity' => 1],
            ],
        ]);

        // Create a manufactured component
        BlueprintActivity::create([
            'blueprint_type_id' => 99908,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 88008, 'name' => 'Manufactured Component', 'quantity' => 1],
            ],
        ]);

        $materials = [
            ['type_id' => 88007, 'name' => 'Reaction Material', 'adjusted_quantity' => 1],
            ['type_id' => 88008, 'name' => 'Manufactured Component', 'adjusted_quantity' => 1],
        ];

        $result = $this->planner->resolveComponentBuildData($materials, 0, 0.0);

        $this->assertTrue($result[88007]['is_reaction']);
        $this->assertFalse($result[88008]['is_reaction']);
    }
}
