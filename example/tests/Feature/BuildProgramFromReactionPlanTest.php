<?php

namespace Tests\Feature;

use App\Models\BlueprintActivity;
use App\Models\BuildProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuildProgramFromReactionPlanTest extends TestCase
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

        // Create a simple reaction formula: formula 77001 produces product 88001
        BlueprintActivity::create([
            'blueprint_type_id' => 77001,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 34, 'name' => 'Moon Goo A', 'quantity' => 100],
                ['type_id' => 35, 'name' => 'Moon Goo B', 'quantity' => 200],
            ],
            'products' => [
                ['type_id' => 88001, 'name' => 'Composite Material', 'quantity' => 100],
            ],
        ]);
    }

    public function test_unauthenticated_user_cannot_create_reaction_build_program(): void
    {
        $response = $this->postJson(route('api.v1.programs.build-programs.store-from-reaction-plan'), [
            'name' => 'Test Program',
            'entries' => [
                ['formula_type_id' => 77001, 'slots' => 1, 'runs_per_slot' => 240],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_can_create_build_program_from_reaction_plan(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store-from-reaction-plan'), [
                'name' => 'My Composite Build Program',
                'entries' => [
                    ['formula_type_id' => 77001, 'formula_name' => 'Composite Material Reaction Formula', 'slots' => 3, 'runs_per_slot' => 240],
                ],
                'settings' => [
                    'reaction_structure_type' => 'tatara',
                    'reaction_rig_me' => 't2',
                    'reaction_system_security' => 'nullsec',
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'My Composite Build Program')
            ->assertJsonPath('data.program_type', 'reaction');

        $this->assertDatabaseHas('build_programs', [
            'user_id' => $this->user->id,
            'name' => 'My Composite Build Program',
            'program_type' => 'reaction',
            't2_type_id' => 88001,
            't2_type_name' => 'Composite Material',
        ]);

        // Verify the program has steps
        $program = BuildProgram::where('user_id', $this->user->id)->first();
        $this->assertNotNull($program);
        $this->assertCount(1, $program->steps);
        $step = $program->steps[0];
        $this->assertEquals(88001, $step['type_id']);
        $this->assertEquals('Composite Material', $step['name']);
        $this->assertEquals(3 * 240 * 100, $step['quantity_required']); // slots * runs * portion_size
        $this->assertEquals('reaction', $step['activity_type']);
        $this->assertEquals(77001, $step['blueprint_type_id']);
    }

    public function test_build_program_steps_have_adjusted_materials(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store-from-reaction-plan'), [
                'name' => 'Test Program',
                'entries' => [
                    ['formula_type_id' => 77001, 'slots' => 1, 'runs_per_slot' => 240],
                ],
                'settings' => [
                    'reaction_structure_type' => 'athanor',
                    'reaction_rig_me' => 'none',
                    'reaction_system_security' => 'lowsec',
                ],
            ]);

        $response->assertCreated();

        $program = BuildProgram::where('user_id', $this->user->id)->first();
        $step = $program->steps[0];

        // Materials should be adjusted for 240 total runs with no rig bonus
        $this->assertCount(2, $step['materials']);
        $moonGooA = collect($step['materials'])->firstWhere('type_id', 34);
        $moonGooB = collect($step['materials'])->firstWhere('type_id', 35);
        $this->assertEquals(100 * 240, $moonGooA['adjusted_quantity']); // 100 per run * 240 runs
        $this->assertEquals(200 * 240, $moonGooB['adjusted_quantity']); // 200 per run * 240 runs
    }

    public function test_validation_requires_entries(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store-from-reaction-plan'), [
                'name' => 'Test Program',
                'entries' => [],
            ]);

        $response->assertUnprocessable();
    }

    public function test_validation_requires_name(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store-from-reaction-plan'), [
                'entries' => [
                    ['formula_type_id' => 77001, 'slots' => 1, 'runs_per_slot' => 240],
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_skips_entries_with_no_matching_formula(): void
    {
        // formula 99999 doesn't exist in DB, should be skipped
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store-from-reaction-plan'), [
                'name' => 'Test Program',
                'entries' => [
                    ['formula_type_id' => 99999, 'slots' => 1, 'runs_per_slot' => 240],
                ],
            ]);

        // Should fail because no valid entries remain
        $response->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.entries.0', 'No valid reaction entries found');
    }

    public function test_user_can_create_multi_entry_program(): void
    {
        // Create a second reaction formula
        BlueprintActivity::create([
            'blueprint_type_id' => 77002,
            'activity_id' => BlueprintActivity::ACTIVITY_REACTION,
            'time' => 3600,
            'materials' => [
                ['type_id' => 36, 'name' => 'Moon Goo C', 'quantity' => 50],
            ],
            'products' => [
                ['type_id' => 88002, 'name' => 'Second Composite', 'quantity' => 100],
            ],
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store-from-reaction-plan'), [
                'name' => 'Multi-Reaction Program',
                'entries' => [
                    ['formula_type_id' => 77001, 'slots' => 3, 'runs_per_slot' => 240],
                    ['formula_type_id' => 77002, 'slots' => 2, 'runs_per_slot' => 120],
                ],
            ]);

        $response->assertCreated();

        $program = BuildProgram::where('user_id', $this->user->id)->first();
        $this->assertCount(2, $program->steps);
    }

    public function test_deleting_user_cascades_to_reaction_build_programs(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store-from-reaction-plan'), [
                'name' => 'Cascade Test',
                'entries' => [
                    ['formula_type_id' => 77001, 'slots' => 1, 'runs_per_slot' => 240],
                ],
            ]);

        $this->assertDatabaseHas('build_programs', ['user_id' => $this->user->id]);

        $this->user->delete();

        $this->assertDatabaseMissing('build_programs', ['user_id' => $this->user->id]);
    }

    public function test_other_user_gets_404_on_show(): void
    {
        $program = BuildProgram::create([
            'user_id' => $this->user->id,
            'name' => 'Private Program',
            'steps' => [],
            'settings' => [],
        ]);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($otherUser)
            ->getJson(route('api.v1.industry-pages.build-programs.show', $program));

        $response->assertNotFound();
    }

    public function test_owner_can_see_their_program(): void
    {
        $program = BuildProgram::create([
            'user_id' => $this->user->id,
            'name' => 'My Program',
            'steps' => [],
            'settings' => [],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.industry-pages.build-programs.show', $program));

        $response->assertOk()->assertJsonPath('data.name', 'My Program');
    }

    public function test_existing_manufacturing_build_program_store_still_works(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store'), [
                'name' => 'T2 Program',
                't2_type_id' => 12345,
                't2_type_name' => 'Stiletto',
                'steps' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'quantity_required' => 1000, 'materials' => []],
                ],
                'settings' => [],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'T2 Program');

        $this->assertDatabaseHas('build_programs', [
            'user_id' => $this->user->id,
            'name' => 'T2 Program',
            't2_type_id' => 12345,
        ]);
    }
}
