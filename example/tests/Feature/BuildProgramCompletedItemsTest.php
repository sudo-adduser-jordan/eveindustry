<?php

namespace Tests\Feature;

use App\Models\BuildProgram;
use App\Models\Character;
use App\Models\EveType;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BuildProgramCompletedItemsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Pilot One',
            'corporation_id' => 98000001,
            'scopes' => [],
        ]);
    }

    public function test_progress_endpoint_reduces_materials_for_completed_items(): void
    {
        $program = BuildProgram::create([
            'user_id' => $this->user->id,
            'name' => 'Reduction Test',
            't2_type_id' => 100,
            't2_type_name' => 'Component A',
            'steps' => [
                [
                    'type_id' => 100,
                    'name' => 'Component A',
                    'quantity_required' => 10,
                    'activity_type' => 'manufacturing',
                    'blueprint_type_id' => 101,
                    'materials' => [
                        ['type_id' => 34, 'name' => 'Tritanium', 'adjusted_quantity' => 1000],
                        ['type_id' => 35, 'name' => 'Pyerite', 'adjusted_quantity' => 500],
                    ],
                    'children' => [],
                ],
            ],
            'settings' => [],
        ]);

        $esi = Mockery::mock(EsiService::class);
        // User has 3 of the completed product + some raw materials
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 100, 'quantity' => 3,   'location_id' => 60003760, 'location_type' => 'station'],
                ['item_id' => 2, 'type_id' => 34,  'quantity' => 400, 'location_id' => 60003760, 'location_type' => 'station'],
                ['item_id' => 3, 'type_id' => 35,  'quantity' => 200, 'location_id' => 60003760, 'location_type' => 'station'],
            ]);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.progress', $program));

        $response->assertOk();

        // Step should show 10 required, 3 available
        $step = $response->json('data.steps.0');
        $this->assertEquals(10, $step['quantity_required']);
        $this->assertEquals(3, $step['available']);

        // Materials should be scaled to 7/10:
        // Tritanium: ceil(1000 * 7/10) = 700
        // Pyerite: ceil(500 * 7/10) = 350
        $trit = collect($step['materials'])->firstWhere('type_id', 34);
        $pye = collect($step['materials'])->firstWhere('type_id', 35);
        $this->assertEquals(700, $trit['required']);
        $this->assertEquals(350, $pye['required']);
    }

    public function test_progress_endpoint_reduces_base_materials_summary(): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'group_id' => 18, 'published' => true]);

        $program = BuildProgram::create([
            'user_id' => $this->user->id,
            'name' => 'Summary Test',
            't2_type_id' => 100,
            't2_type_name' => 'Component A',
            'steps' => [
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
            ],
            'settings' => [],
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 100, 'quantity' => 6,   'location_id' => 60003760, 'location_type' => 'station'],
                ['item_id' => 2, 'type_id' => 34,  'quantity' => 100, 'location_id' => 60003760, 'location_type' => 'station'],
            ]);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.progress', $program));

        $response->assertOk();

        // Base materials should reflect reduced quantity: ceil(500 * 4/10) = 200
        $baseMaterials = $response->json('data.base_materials');
        $trit = collect($baseMaterials)->firstWhere('type_id', 34);
        $this->assertNotNull($trit);
        $this->assertEquals(200, $trit['quantity']);
    }

    public function test_progress_endpoint_reduces_nested_children_and_their_materials(): void
    {
        $program = BuildProgram::create([
            'user_id' => $this->user->id,
            'name' => 'Nested Test',
            't2_type_id' => 100,
            't2_type_name' => 'T2 Item',
            'steps' => [
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
            ],
            'settings' => [],
        ]);

        $esi = Mockery::mock(EsiService::class);
        // User has 4 of the T2 Item and 10 of the Intermediate
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 100, 'quantity' => 4,  'location_id' => 60003760, 'location_type' => 'station'],
                ['item_id' => 2, 'type_id' => 200, 'quantity' => 10, 'location_id' => 60003760, 'location_type' => 'station'],
                ['item_id' => 3, 'type_id' => 34,  'quantity' => 50, 'location_id' => 60003760, 'location_type' => 'station'],
            ]);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.progress', $program));

        $response->assertOk();

        // Parent: 10 required, 4 available → need 6 more → childScale = 6/10
        $parent = $response->json('data.steps.0');
        $this->assertEquals(10, $parent['quantity_required']);
        $this->assertEquals(4, $parent['available']);

        // Child: ceil(50 * 6/10) = 30 required, 10 available → need 20 more
        $child = $parent['children'][0];
        $this->assertEquals(30, $child['quantity_required']);
        $this->assertEquals(10, $child['available']);

        // Child's material: childScale = 20/50 = 0.4, ceil(500 * 0.4) = 200
        $childMat = collect($child['materials'])->firstWhere('type_id', 34);
        $this->assertEquals(200, $childMat['required']);
    }

    public function test_progress_endpoint_fully_completed_step_zeroes_materials(): void
    {
        $program = BuildProgram::create([
            'user_id' => $this->user->id,
            'name' => 'Full Complete Test',
            't2_type_id' => 100,
            't2_type_name' => 'Component A',
            'steps' => [
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
            ],
            'settings' => [],
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 100, 'quantity' => 10, 'location_id' => 60003760, 'location_type' => 'station'],
            ]);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.progress', $program));

        $response->assertOk();

        $step = $response->json('data.steps.0');
        $this->assertTrue($step['has_enough']);

        $mat = $step['materials'][0];
        $this->assertEquals(0, $mat['required']);
        $this->assertTrue($mat['has_enough']);
    }
}
