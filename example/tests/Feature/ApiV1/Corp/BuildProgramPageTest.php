<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Models\CorporationBuildProgram;
use App\Models\CorporationBuildProgramConfig;
use App\Models\EveType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Programs\ProgramsFixtures;
use Tests\TestCase;

class BuildProgramPageTest extends TestCase
{
    use ProgramsFixtures, RefreshDatabase;

    private function program(int $corporationId = 98000001, array $attrs = []): CorporationBuildProgram
    {
        return CorporationBuildProgram::create(array_merge([
            'corporation_id' => $corporationId,
            'created_by_user_id' => User::query()->value('id') ?? $this->makeUser()->id,
            'name' => 'Corp Rifters',
            'program_type' => 'manufacturing',
            't2_type_id' => 587,
            't2_type_name' => 'Rifter',
            'steps' => [[
                'type_id' => 587,
                'name' => 'Rifter',
                'quantity_required' => 10,
                'runs_needed' => 10,
                'activity_type' => 'manufacturing',
                'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100, 'adjusted_quantity' => 90]],
                'children' => [['type_id' => 35, 'name' => 'Pyerite', 'quantity_required' => 5, 'materials' => [], 'children' => []]],
            ]],
            'settings' => ['input_location_ids' => ['60003760'], 'sections' => [['name' => 'Hulls', 'step_count' => 1]]],
        ], $attrs));
    }

    /** A plain member of the corporation; unlike corpUser() it leaves the config alone. */
    private function member()
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        return $user;
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/corporation/build-programs')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/corporation/build-programs/1')->assertStatus(401);
        $this->postJson('/api/v1/corporation/build-programs/enable')->assertStatus(401);
        $this->postJson('/api/v1/corporation/build-programs/disable')->assertStatus(401);
    }

    public function test_overview_when_not_enabled_lists_eligible_characters(): void
    {
        $user = $this->makeUser();
        $director = $this->makeCharacter($user, ['is_director_ceo' => true, 'scopes' => ['esi-assets.read_corporation_assets.v1']]);

        $this->actingAs($user)->getJson('/api/v1/corporation/build-programs')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.can_view', false)
            ->assertJsonPath('data.programs', [])
            ->assertJsonPath('data.eligible_characters.0.character_id', $director->character_id);
    }

    public function test_overview_lists_programs_with_filters_for_a_manager(): void
    {
        $user = $this->corpUser('director');
        $this->program();
        $this->program(99999999, ['name' => 'Foreign']);

        $this->actingAs($user)->getJson('/api/v1/corporation/build-programs')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.enabled_by_me', true)
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.corporation_name', 'Test Corp')
            ->assertJsonCount(1, 'data.programs')
            ->assertJsonPath('data.programs.0.name', 'Corp Rifters')
            ->assertJsonPath('data.programs.0.step_count', 1)
            ->assertJsonPath('data.programs.0.input_location_ids', [60003760])
            ->assertJsonPath('data.programs.0.output_location_ids', []);
    }

    public function test_plain_member_can_view_but_not_manage(): void
    {
        $this->corpUser('director');
        $this->program();
        $member = $this->member();

        $this->actingAs($member)->getJson('/api/v1/corporation/build-programs')
            ->assertOk()
            ->assertJsonPath('data.can_view', true)
            ->assertJsonPath('data.can_manage', false)
            ->assertJsonPath('data.enabled_by_me', false)
            ->assertJsonCount(1, 'data.programs');
    }

    public function test_user_outside_the_corporation_sees_no_programs(): void
    {
        $this->corpUser('director');
        $this->program();

        $outsider = $this->makeUser();
        $this->makeCharacter($outsider, ['corporation_id' => 99999999]);

        $this->actingAs($outsider)->getJson('/api/v1/corporation/build-programs')
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.programs', []);
    }

    public function test_show_returns_the_step_tree_sections_and_volumes(): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'group_id' => 18, 'volume' => 0.01, 'packaged_volume' => 0.01, 'published' => true]);
        $user = $this->corpUser('director');
        $program = $this->program();

        $this->actingAs($user)->getJson("/api/v1/corporation/build-programs/{$program->id}")
            ->assertOk()
            ->assertJsonPath('data.can_manage', true)
            ->assertJsonPath('data.corporation_name', 'Test Corp')
            ->assertJsonPath('data.provider_character_name', 'Main Pilot')
            ->assertJsonPath('data.program.name', 'Corp Rifters')
            ->assertJsonPath('data.program.steps.0.runs_needed', 10)
            ->assertJsonPath('data.program.steps.0.activity_type', 'manufacturing')
            ->assertJsonPath('data.program.steps.0.materials.0.quantity', 90)
            ->assertJsonPath('data.program.steps.0.children.0.name', 'Pyerite')
            ->assertJsonPath('data.program.sections', [['name' => 'Hulls', 'step_count' => 1]])
            ->assertJsonPath('data.volumes.34', 0.01);
    }

    public function test_show_is_404_for_another_corporation_and_without_the_feature(): void
    {
        $foreign = $this->program(99999999);
        $user = $this->corpUser('director');
        $stranger = $this->makeUser();
        $this->makeCharacter($stranger, ['corporation_id' => 55555555]);

        $this->actingAs($user)->getJson("/api/v1/corporation/build-programs/{$foreign->id}")->assertNotFound();
        $this->actingAs($stranger)->getJson("/api/v1/corporation/build-programs/{$foreign->id}")->assertNotFound();
    }

    public function test_enable_with_the_default_and_a_chosen_character(): void
    {
        $user = $this->makeUser();
        $director = $this->makeCharacter($user, ['is_director_ceo' => true, 'scopes' => ['esi-assets.read_corporation_assets.v1']]);

        $this->actingAs($user)->postJson('/api/v1/corporation/build-programs/enable', ['character_id' => $director->character_id])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.enabled_by_me', true)
            ->assertJsonPath('data.provider_character_name', 'Main Pilot');

        $this->assertTrue(CorporationBuildProgramConfig::first()->is_active);
    }

    public function test_enable_without_an_eligible_character_is_422(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['is_director_ceo' => false]);

        $this->actingAs($user)->postJson('/api/v1/corporation/build-programs/enable')
            ->assertStatus(422)->assertJsonValidationErrors(['character_id']);
    }

    public function test_only_the_enabler_may_disable_and_programs_survive(): void
    {
        $enabler = $this->corpUser('director');
        $program = $this->program();
        $member = $this->member();

        $this->actingAs($member)->postJson('/api/v1/corporation/build-programs/disable')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');

        $this->actingAs($enabler)->postJson('/api/v1/corporation/build-programs/disable')
            ->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.programs', []);

        $this->assertDatabaseHas('corporation_build_programs', ['id' => $program->id]);
    }
}
