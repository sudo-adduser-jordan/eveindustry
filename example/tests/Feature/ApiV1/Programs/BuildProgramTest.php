<?php

namespace Tests\Feature\ApiV1\Programs;

use App\Models\BuildProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Programs\ProgramsFixtures;
use Tests\TestCase;

class BuildProgramTest extends TestCase
{
    use ProgramsFixtures, RefreshDatabase;

    private const RESOURCE_KEYS = ['id', 'name', 'program_type', 't2_type_id', 't2_type_name', 'steps', 'settings', 'created_at', 'updated_at'];

    public function test_requires_auth(): void
    {
        $this->postJson('/api/v1/build-programs', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/build-programs/from-reaction-plan', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/build-programs/merge', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->putJson('/api/v1/build-programs/1', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->patchJson('/api/v1/build-programs/1/location-filter', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->deleteJson('/api/v1/build-programs/1')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_store_returns_201_with_the_resource_and_persists(): void
    {
        $this->seedBlueprints();
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/v1/build-programs', $this->storePayload())
            ->assertCreated()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS, 'meta'])
            ->assertJsonPath('data.name', 'My Program')
            ->assertJsonPath('data.program_type', 'manufacturing')
            ->assertJsonPath('data.t2_type_id', 587)
            ->assertJsonPath('data.steps.0.blueprint_type_id', 1000)
            ->assertJsonPath('data.steps.0.activity_type', 'manufacturing')
            ->assertJsonPath('data.settings.me', 10)
            ->assertJsonMissingPath('data.user_id');

        $program = BuildProgram::firstOrFail();
        $this->assertSame($user->id, $program->user_id);
        $this->assertSame($program->id, $response->json('data.id'));
    }

    public function test_store_without_optional_t2_fields_works(): void
    {
        $user = $this->makeUser();
        $payload = $this->storePayload();
        unset($payload['t2_type_id'], $payload['t2_type_name'], $payload['settings']);

        $this->actingAs($user)->postJson('/api/v1/build-programs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.t2_type_id', null)
            ->assertJsonPath('data.t2_type_name', null)
            ->assertJsonPath('data.settings', []);
    }

    public function test_store_validation_is_422(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/build-programs', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'steps']);

        $this->assertSame(0, BuildProgram::count());
    }

    public function test_store_from_reaction_plan(): void
    {
        $this->seedBlueprints();
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/build-programs/from-reaction-plan', $this->reactionPayload())
            ->assertCreated()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS])
            ->assertJsonPath('data.program_type', 'reaction')
            ->assertJsonPath('data.t2_type_id', 4312)
            ->assertJsonPath('data.steps.0.quantity_required', 100)
            ->assertJsonPath('data.steps.0.runs_needed', 10);

        $this->assertSame(1, BuildProgram::where('user_id', $user->id)->count());
    }

    public function test_store_from_reaction_plan_without_known_formula_is_422(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/build-programs/from-reaction-plan', $this->reactionPayload())
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['entries']);

        $this->actingAs($user)->postJson('/api/v1/build-programs/from-reaction-plan', [])
            ->assertStatus(422)->assertJsonValidationErrors(['name', 'entries']);

        $this->assertSame(0, BuildProgram::count());
    }

    public function test_merge_creates_a_new_program_and_keeps_the_sources(): void
    {
        $user = $this->makeUser();
        $a = $this->personalProgram($user, ['name' => 'A', 'settings' => ['me' => 5]]);
        $b = $this->personalProgram($user, ['name' => 'B', 'program_type' => 'reaction']);

        $this->actingAs($user)->postJson('/api/v1/build-programs/merge', [
            'program_ids' => [$a->id, $b->id], 'name' => 'Merged',
        ])->assertCreated()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS])
            ->assertJsonPath('data.name', 'Merged')
            ->assertJsonPath('data.program_type', 'manufacturing')
            ->assertJsonPath('data.settings.sections.0.name', 'A')
            ->assertJsonPath('data.settings.sections.1.name', 'B')
            ->assertJsonCount(2, 'data.steps');

        $this->assertSame(3, BuildProgram::count());
        $this->assertNotNull($a->fresh());
        $this->assertNotNull($b->fresh());
    }

    public function test_merge_with_fewer_than_two_of_your_programs_is_422(): void
    {
        $user = $this->makeUser();
        $mine = $this->personalProgram($user);
        $theirs = $this->personalProgram($this->makeUser());

        $this->actingAs($user)->postJson('/api/v1/build-programs/merge', [
            'program_ids' => [$mine->id, $theirs->id], 'name' => 'M',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['program_ids']);

        $this->actingAs($user)->postJson('/api/v1/build-programs/merge', ['program_ids' => [$mine->id]])
            ->assertStatus(422)->assertJsonValidationErrors(['program_ids', 'name']);

        $this->assertSame(2, BuildProgram::count());
    }

    public function test_update_renames_and_returns_the_resource(): void
    {
        $user = $this->makeUser();
        $program = $this->personalProgram($user);

        $this->actingAs($user)->putJson("/api/v1/build-programs/{$program->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS])
            ->assertJsonPath('data.id', $program->id)
            ->assertJsonPath('data.name', 'Renamed');

        $this->assertSame('Renamed', $program->fresh()->name);

        $this->actingAs($user)->putJson("/api/v1/build-programs/{$program->id}", [])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['name']);
    }

    public function test_location_filter_is_stored_in_settings(): void
    {
        $user = $this->makeUser();
        $program = $this->personalProgram($user);

        $this->actingAs($user)->patchJson("/api/v1/build-programs/{$program->id}/location-filter", ['location_ids' => [60003760, '60008494']])
            ->assertOk()
            ->assertJsonPath('data.settings.location_ids', [60003760, 60008494])
            ->assertJsonPath('data.settings.me', 10);

        $this->assertSame([60003760, 60008494], $program->fresh()->settings['location_ids']);

        $this->actingAs($user)->patchJson("/api/v1/build-programs/{$program->id}/location-filter", [])
            ->assertOk()->assertJsonPath('data.settings.location_ids', []);

        $this->actingAs($user)->patchJson("/api/v1/build-programs/{$program->id}/location-filter", ['location_ids' => ['x']])
            ->assertStatus(422)->assertJsonValidationErrors(['location_ids.0']);
    }

    public function test_destroy_returns_204_and_deletes(): void
    {
        $user = $this->makeUser();
        $program = $this->personalProgram($user);

        $this->actingAs($user)->deleteJson("/api/v1/build-programs/{$program->id}")
            ->assertNoContent();

        $this->assertNull($program->fresh());
    }

    public function test_other_users_program_is_404_not_found(): void
    {
        $user = $this->makeUser();
        $theirs = $this->personalProgram($this->makeUser());

        $this->actingAs($user)->putJson("/api/v1/build-programs/{$theirs->id}", ['name' => 'Hijack'])
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($user)->patchJson("/api/v1/build-programs/{$theirs->id}/location-filter", ['location_ids' => [1]])
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($user)->deleteJson("/api/v1/build-programs/{$theirs->id}")
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($user)->deleteJson('/api/v1/build-programs/99999')
            ->assertNotFound()->assertJsonPath('code', 'not_found');

        $this->assertSame('Rifters', $theirs->fresh()->name);
        $this->assertSame(['me' => 10], $theirs->fresh()->settings);
    }

    public function test_ownership_is_checked_before_validation(): void
    {
        $user = $this->makeUser();
        $theirs = $this->personalProgram($this->makeUser());

        $this->actingAs($user)->putJson("/api/v1/build-programs/{$theirs->id}", [])
            ->assertNotFound();
    }
}
