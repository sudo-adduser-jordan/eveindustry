<?php

namespace Tests\Feature\ApiV1\Heavy;

use App\Models\SdeSolarSystem;
use App\Models\SdeStation;
use App\Services\Esi\EsiRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Heavy\HeavyFixtures;
use Tests\TestCase;

class BuildProgramProgressTest extends TestCase
{
    use HeavyFixtures, RefreshDatabase;

    private const CORP = 98000001;

    private function assets(): array
    {
        return [
            $this->asset(['item_id' => 1, 'type_id' => 34, 'quantity' => 60]),
            $this->asset(['item_id' => 2, 'type_id' => 5000, 'quantity' => 4, 'location_id' => 60008494]),
        ];
    }

    private function corpSetup(): array
    {
        $user = $this->makeUser();
        $director = $this->makeCharacter($user, ['is_director_ceo' => true, 'name' => 'Boss']);
        $this->enableCorpPrograms($director, $user->id);

        return [$user, $director, $this->makeCorpProgram(self::CORP)];
    }

    public function test_requires_auth(): void
    {
        $user = $this->makeUser();
        $program = $this->makeProgram($user);

        foreach (['progress', 'asset-locations'] as $path) {
            $this->getJson("/api/v1/build-programs/{$program->id}/{$path}")->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
            $this->getJson("/api/v1/corporation/build-programs/{$program->id}/{$path}")->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_progress_envelope_and_values(): void
    {
        $this->seedTypes();
        $user = $this->makeUser();
        $this->makeCharacter($user, ['name' => 'Alpha']);
        $program = $this->makeProgram($user, ['settings' => ['sections' => [['name' => 'Only', 'step_count' => 1]]]]);
        $this->mockEsi()->shouldReceive('getCharacterAssets')->once()->andReturn($this->assets());

        $response = $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/progress")->assertOk();

        $response->assertJsonStructure(['data' => ['character_names', 'overall_progress', 'steps', 'required_blueprints', 'base_materials', 'fuel_blocks', 'reactions_summary', 'sections'], 'meta'])
            ->assertJsonPath('data.character_names', ['Alpha'])
            ->assertJsonPath('data.steps.0.available', 4)
            ->assertJsonPath('data.steps.0.materials.0.required', 60)
            ->assertJsonPath('data.steps.0.materials.0.available', 60)
            ->assertJsonPath('data.required_blueprints.0.name', 'Widget Blueprint')
            ->assertJsonPath('data.sections.0.name', 'Only')
            ->assertJsonMissingPath('success');
        // A program carries no prices or costs: no freshness block.
        $this->assertArrayNotHasKey('freshness', (array) $response->json('meta'));
        $this->assertStringNotContainsString('secret-access-token', $response->getContent());
    }

    public function test_progress_location_filter_and_validation(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);
        $program = $this->makeProgram($user);
        $this->mockEsi()->shouldReceive('getCharacterAssets')->once()->andReturn($this->assets());

        $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/progress?location_ids[]=60003760")
            ->assertOk()
            ->assertJsonPath('data.steps.0.available', 0)
            ->assertJsonPath('data.steps.0.materials.0.available', 60);

        $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/progress?location_ids[]=abc")
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('location_ids.0');
    }

    public function test_another_users_program_is_404(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $this->makeCharacter($other);
        $program = $this->makeProgram($owner);

        foreach (['progress', 'asset-locations'] as $path) {
            $this->actingAs($other)->getJson("/api/v1/build-programs/{$program->id}/{$path}")
                ->assertStatus(404)->assertJsonPath('code', 'not_found');
        }
        $this->actingAs($other)->getJson('/api/v1/build-programs/9999/progress')->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_progress_without_characters_is_400(): void
    {
        $user = $this->makeUser();
        $program = $this->makeProgram($user);

        $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/progress")
            ->assertStatus(400)->assertJsonPath('code', 'bad_request')->assertJsonPath('message', 'No characters linked');
    }

    public function test_asset_locations_are_paginated(): void
    {
        SdeSolarSystem::forceCreate(['solar_system_id' => 30000142, 'solar_system_name' => 'Jita', 'region_id' => 10000002, 'security' => 0.9]);
        SdeStation::forceCreate(['station_id' => 60003760, 'station_name' => 'Jita IV - Moon 4', 'solar_system_id' => 30000142]);
        $user = $this->makeUser();
        $this->makeCharacter($user);
        $program = $this->makeProgram($user);
        $this->mockEsi()->shouldReceive('getCharacterAssets')->twice()->andReturn([
            $this->asset(['item_id' => 1, 'location_id' => 60003760]),
            $this->asset(['item_id' => 3, 'location_id' => 60009999]),
        ]);

        $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/asset-locations")
            ->assertOk()
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 50, 'total' => 2, 'last_page' => 1])
            ->assertJsonPath('data.0', ['location_id' => 60003760, 'name' => 'Jita IV - Moon 4', 'solar_system_id' => 30000142, 'solar_system_name' => 'Jita'])
            ->assertJsonPath('data.1.name', 'Location #60009999');

        $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/asset-locations?per_page=1&page=2")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.location_id', 60009999);
        $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/asset-locations?per_page=500")
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_revoked_and_inactive_characters_are_skipped(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['name' => 'Alpha']);
        $revoked = $this->makeCharacter($user, ['name' => 'Revoked']);
        $this->makeCharacter($user, ['name' => 'Inactive', 'is_esi_active' => false]);
        $program = $this->makeProgram($user);
        $this->mockEsi()->shouldReceive('getCharacterAssets')->times(4)
            ->andReturnUsing(fn ($char) => $char->is($revoked)
                ? throw new EsiRequestException('credential')
                : $this->assets());

        $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.character_names', ['Alpha']);
        $this->actingAs($user)->getJson("/api/v1/build-programs/{$program->id}/asset-locations")
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_corporate_progress(): void
    {
        $this->seedTypes();
        [$user, $director, $program] = $this->corpSetup();
        $this->mockEsi()->shouldReceive('getCorporationAssets')->twice()
            ->withArgs(fn ($char, $corp) => $char->character_id === $director->character_id && $corp === self::CORP)
            ->andReturn([
                $this->asset(['item_id' => 1, 'type_id' => 34, 'quantity' => 100]),
                $this->asset(['item_id' => 2, 'type_id' => 5000, 'quantity' => 5, 'location_id' => 60008494]),
            ]);

        $this->actingAs($user)->getJson("/api/v1/corporation/build-programs/{$program->id}/progress")
            ->assertOk()
            ->assertJsonPath('data.character_names', ['Boss'])
            ->assertJsonPath('data.steps.0.available', 5)
            ->assertJsonPath('data.steps.0.materials.0.available', 100)
            ->assertJsonPath('data.required_blueprints.0.name', 'Widget Blueprint');

        $this->actingAs($user)
            ->getJson("/api/v1/corporation/build-programs/{$program->id}/progress?input_location_ids[]=60008494&output_location_ids[]=60003760")
            ->assertOk()
            ->assertJsonPath('data.steps.0.available', 0)
            ->assertJsonPath('data.steps.0.materials.0.available', 0);
    }

    public function test_corporate_program_of_another_corporation_or_without_config_is_404(): void
    {
        [$user] = $this->corpSetup();
        $foreign = $this->makeCorpProgram(98000099);

        foreach (['progress', 'asset-locations'] as $path) {
            $this->actingAs($user)->getJson("/api/v1/corporation/build-programs/{$foreign->id}/{$path}")
                ->assertStatus(404)->assertJsonPath('code', 'not_found');
        }

        $outsider = $this->makeUser();
        $this->makeCharacter($outsider, ['corporation_id' => 98000555]);
        $program = $this->makeCorpProgram(self::CORP);
        $this->actingAs($outsider)->getJson("/api/v1/corporation/build-programs/{$program->id}/progress")
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_corporate_asset_locations(): void
    {
        SdeSolarSystem::forceCreate(['solar_system_id' => 30000142, 'solar_system_name' => 'Jita', 'region_id' => 10000002, 'security' => 0.9]);
        SdeStation::forceCreate(['station_id' => 60003760, 'station_name' => 'Jita IV - Moon 4', 'solar_system_id' => 30000142]);
        [$user, , $program] = $this->corpSetup();
        $this->mockEsi()->shouldReceive('getCorporationAssets')->once()->andReturn([
            $this->asset(['item_id' => 1, 'location_id' => 60003760]),
            $this->asset(['item_id' => 2, 'location_id' => 60008494]),
        ]);

        $this->actingAs($user)->getJson("/api/v1/corporation/build-programs/{$program->id}/asset-locations")
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.name', 'Jita IV - Moon 4')
            ->assertJsonPath('data.1.solar_system_name', null);
    }
}
