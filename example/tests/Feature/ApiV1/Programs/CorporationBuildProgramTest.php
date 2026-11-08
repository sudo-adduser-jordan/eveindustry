<?php

namespace Tests\Feature\ApiV1\Programs;

use App\Models\CorporationBuildProgram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\LegacyApi\Programs\ProgramsFixtures;
use Tests\TestCase;

class CorporationBuildProgramTest extends TestCase
{
    use ProgramsFixtures, RefreshDatabase;

    private const RESOURCE_KEYS = ['id', 'corporation_id', 'created_by_user_id', 'name', 'program_type', 't2_type_id', 't2_type_name', 'steps', 'settings', 'created_at', 'updated_at'];

    public function test_requires_auth(): void
    {
        $this->postJson('/api/v1/corporation/build-programs', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/corporation/build-programs/from-reaction-plan', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/corporation/build-programs/merge', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->putJson('/api/v1/corporation/build-programs/1', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->patchJson('/api/v1/corporation/build-programs/1/location-filter', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->deleteJson('/api/v1/corporation/build-programs/1')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function managerRoles(): array
    {
        return ['director/CEO' => ['director'], 'factory manager' => ['factory']];
    }

    #[DataProvider('managerRoles')]
    public function test_store_as_manager_returns_201(string $role): void
    {
        $this->seedBlueprints();
        $user = $this->corpUser($role);

        $this->actingAs($user)->postJson('/api/v1/corporation/build-programs', $this->storePayload())
            ->assertCreated()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS])
            ->assertJsonPath('data.corporation_id', 98000001)
            ->assertJsonPath('data.created_by_user_id', $user->id)
            ->assertJsonPath('data.steps.0.blueprint_type_id', 1000);

        $this->assertSame(1, CorporationBuildProgram::where('corporation_id', 98000001)->count());
    }

    public function test_create_and_merge_are_403_forbidden_without_the_feature_or_role(): void
    {
        $stranger = $this->makeUser();
        $this->makeCharacter($stranger, ['corporation_id' => 98000777]);
        $member = $this->corpUser('member');

        foreach ([$stranger, $member] as $user) {
            // 403 comes before validation, so an empty body still reads as forbidden.
            foreach (['', '/from-reaction-plan', '/merge'] as $suffix) {
                $this->actingAs($user)->postJson("/api/v1/corporation/build-programs{$suffix}", [])
                    ->assertForbidden()
                    ->assertJsonPath('code', 'forbidden');
            }
        }

        $this->actingAs($stranger)->postJson('/api/v1/corporation/build-programs', $this->storePayload())
            ->assertForbidden()->assertJsonPath('message', 'Corporate build programs not enabled');
        $this->actingAs($member)->postJson('/api/v1/corporation/build-programs', $this->storePayload())
            ->assertForbidden()->assertJsonPath('message', 'Insufficient role. Director, CEO, or Factory Manager required.');

        $this->assertSame(0, CorporationBuildProgram::count());
    }

    public function test_store_validation_is_422(): void
    {
        $user = $this->corpUser();

        $this->actingAs($user)->postJson('/api/v1/corporation/build-programs', [])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['name', 'steps']);
    }

    public function test_store_from_reaction_plan(): void
    {
        $this->seedBlueprints();
        $user = $this->corpUser('factory');

        $this->actingAs($user)->postJson('/api/v1/corporation/build-programs/from-reaction-plan', $this->reactionPayload())
            ->assertCreated()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS])
            ->assertJsonPath('data.program_type', 'reaction')
            ->assertJsonPath('data.t2_type_id', 4312);

        $this->actingAs($user)->postJson('/api/v1/corporation/build-programs/from-reaction-plan', $this->reactionPayload([
            'entries' => [['formula_type_id' => 31337, 'slots' => 1, 'runs_per_slot' => 1]],
        ]))->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['entries']);

        $this->assertSame(1, CorporationBuildProgram::count());
    }

    public function test_merge_only_uses_your_corporations_programs(): void
    {
        $user = $this->corpUser();
        $a = $this->corpProgram(98000001, ['name' => 'A']);
        $b = $this->corpProgram(98000001, ['name' => 'B']);
        $foreign = $this->corpProgram(98000002, ['name' => 'F']);

        $this->actingAs($user)->postJson('/api/v1/corporation/build-programs/merge', [
            'program_ids' => [$a->id, $foreign->id], 'name' => 'Nope',
        ])->assertStatus(422)->assertJsonValidationErrors(['program_ids']);

        $this->actingAs($user)->postJson('/api/v1/corporation/build-programs/merge', [
            'program_ids' => [$b->id, $a->id], 'name' => 'Merged',
        ])->assertCreated()
            ->assertJsonStructure(['data' => self::RESOURCE_KEYS])
            ->assertJsonPath('data.corporation_id', 98000001)
            ->assertJsonPath('data.settings.sections.0.name', 'B')
            ->assertJsonCount(2, 'data.steps');

        $this->assertSame(4, CorporationBuildProgram::count());
        $this->assertNotNull($a->fresh());
    }

    #[DataProvider('managerRoles')]
    public function test_manager_can_rename_filter_and_delete(string $role): void
    {
        $user = $this->corpUser($role);
        $program = $this->corpProgram();

        $this->actingAs($user)->putJson("/api/v1/corporation/build-programs/{$program->id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonStructure(['data' => self::RESOURCE_KEYS])->assertJsonPath('data.name', 'Renamed');

        $this->actingAs($user)->patchJson("/api/v1/corporation/build-programs/{$program->id}/location-filter", [
            'input_location_ids' => ['5', 6],
        ])->assertOk()
            ->assertJsonPath('data.settings.input_location_ids', [5, 6])
            ->assertJsonPath('data.settings.output_location_ids', []);

        $this->actingAs($user)->patchJson("/api/v1/corporation/build-programs/{$program->id}/location-filter", ['output_location_ids' => ['x']])
            ->assertStatus(422)->assertJsonValidationErrors(['output_location_ids.0']);

        $this->actingAs($user)->deleteJson("/api/v1/corporation/build-programs/{$program->id}")->assertNoContent();
        $this->assertNull($program->fresh());
    }

    public function test_update_and_delete_are_404_without_manage_rights_on_that_program(): void
    {
        $member = $this->corpUser('member');
        $program = $this->corpProgram();
        $foreign = $this->corpProgram(98000002);
        $director = $this->corpUser('director');
        $stranger = $this->makeUser();

        foreach ([$member, $stranger] as $user) {
            $this->actingAs($user)->putJson("/api/v1/corporation/build-programs/{$program->id}", ['name' => 'x'])
                ->assertNotFound()->assertJsonPath('code', 'not_found');
            $this->actingAs($user)->patchJson("/api/v1/corporation/build-programs/{$program->id}/location-filter", [])
                ->assertNotFound()->assertJsonPath('code', 'not_found');
            $this->actingAs($user)->deleteJson("/api/v1/corporation/build-programs/{$program->id}")
                ->assertNotFound()->assertJsonPath('code', 'not_found');
        }

        // A director of another corporation cannot touch this one's programs.
        $this->actingAs($director)->deleteJson("/api/v1/corporation/build-programs/{$foreign->id}")
            ->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($director)->deleteJson('/api/v1/corporation/build-programs/99999')
            ->assertNotFound()->assertJsonPath('code', 'not_found');

        $this->assertSame(2, CorporationBuildProgram::count());
        $this->assertSame('Corp Rifters', $program->fresh()->name);
    }
}
