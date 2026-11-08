<?php

namespace Tests\Feature;

use App\Models\BuildProgram;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManufacturingMultiItemBuildProgramTest extends TestCase
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
    }

    public function test_can_create_multi_item_build_program_with_sections(): void
    {
        // Simulate what the multi-item export does: combine steps from multiple items
        // with section metadata in settings
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store'), [
                'name' => 'Manufacturing Build Program (2 items)',
                't2_type_id' => null,
                't2_type_name' => null,
                'steps' => [
                    // Steps from item 1 (e.g., Stiletto)
                    ['type_id' => 34, 'name' => 'Tritanium', 'quantity_required' => 5000, 'materials' => []],
                    ['type_id' => 35, 'name' => 'Pyerite', 'quantity_required' => 2000, 'materials' => []],
                    // Steps from item 2 (e.g., Claw)
                    ['type_id' => 36, 'name' => 'Mexallon', 'quantity_required' => 3000, 'materials' => []],
                    ['type_id' => 37, 'name' => 'Isogen', 'quantity_required' => 1000, 'materials' => []],
                ],
                'settings' => [
                    'me_level' => 10,
                    'te_level' => 20,
                    'structure_type' => 'raitaru',
                    'system_security' => 'highsec',
                    'sections' => [
                        ['name' => 'Stiletto', 'step_count' => 2],
                        ['name' => 'Claw', 'step_count' => 2],
                    ],
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Manufacturing Build Program (2 items)');

        $program = BuildProgram::where('user_id', $this->user->id)->first();
        $this->assertNotNull($program);
        $this->assertCount(4, $program->steps);
        $this->assertNull($program->t2_type_id);
        $this->assertNull($program->t2_type_name);

        // Verify section metadata is stored
        $settings = $program->settings;
        $this->assertArrayHasKey('sections', $settings);
        $this->assertCount(2, $settings['sections']);
        $this->assertEquals('Stiletto', $settings['sections'][0]['name']);
        $this->assertEquals(2, $settings['sections'][0]['step_count']);
        $this->assertEquals('Claw', $settings['sections'][1]['name']);
        $this->assertEquals(2, $settings['sections'][1]['step_count']);
    }

    public function test_single_item_build_program_still_works(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store'), [
                'name' => 'Stiletto Build Program',
                't2_type_id' => 11377,
                't2_type_name' => 'Stiletto',
                'steps' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'quantity_required' => 5000, 'materials' => []],
                ],
                'settings' => [
                    'me_level' => 10,
                    'te_level' => 20,
                ],
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Stiletto Build Program');

        $program = BuildProgram::where('user_id', $this->user->id)->first();
        $this->assertEquals(11377, $program->t2_type_id);
        $this->assertEquals('Stiletto', $program->t2_type_name);
    }

    public function test_multi_item_build_program_requires_authentication(): void
    {
        $response = $this->postJson(route('api.v1.programs.build-programs.store'), [
            'name' => 'Test Program',
            'steps' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity_required' => 1000, 'materials' => []],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_multi_item_build_program_requires_steps(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store'), [
                'name' => 'Empty Program',
                'steps' => [],
            ]);

        $response->assertUnprocessable();
    }

    public function test_multi_item_build_program_requires_name(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson(route('api.v1.programs.build-programs.store'), [
                'steps' => [
                    ['type_id' => 34, 'name' => 'Tritanium', 'quantity_required' => 1000, 'materials' => []],
                ],
            ]);

        $response->assertUnprocessable();
    }
}
