<?php

namespace Tests\Feature;

use App\Models\BuildProgram;
use App\Models\Character;
use App\Models\PlayerOwnedStructure;
use App\Models\SdeStation;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BuildProgramLocationFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private BuildProgram $program;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->program = BuildProgram::create([
            'user_id' => $this->user->id,
            'name' => 'Test Program',
            't2_type_id' => 100,
            't2_type_name' => 'Test Item II',
            'steps' => [
                [
                    'type_id' => 34,
                    'name' => 'Tritanium',
                    'quantity_required' => 1000,
                    'materials' => [],
                    'children' => [],
                ],
            ],
            'settings' => [],
        ]);
    }

    // ── progress endpoint ────────────────────────────────────────────

    public function test_progress_without_filter_sums_all_assets(): void
    {
        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Pilot One',
            'corporation_id' => 98000001,
            'scopes' => [],
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 34, 'quantity' => 400, 'location_id' => 60003760, 'location_type' => 'station'],
                ['item_id' => 2, 'type_id' => 34, 'quantity' => 600, 'location_id' => 1000000000001, 'location_type' => 'item'],
            ]);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.progress', $this->program));

        $response->assertOk();
        $this->assertEquals(100.0, $response->json('data.overall_progress')); // 1000/1000 = 100%
    }

    public function test_progress_with_location_filter_sums_only_matching_assets(): void
    {
        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Pilot One',
            'corporation_id' => 98000001,
            'scopes' => [],
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                // 400 units at Jita (station 60003760)
                ['item_id' => 1, 'type_id' => 34, 'quantity' => 400, 'location_id' => 60003760, 'location_type' => 'station'],
                // 600 units at another station (60004558)
                ['item_id' => 2, 'type_id' => 34, 'quantity' => 600, 'location_id' => 60004558, 'location_type' => 'station'],
            ]);
        $this->app->instance(EsiService::class, $esi);

        // Filter to only Jita (60003760)
        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.progress', $this->program).'?location_ids[]=60003760');

        $response->assertOk();
        $this->assertEquals(40.0, $response->json('data.overall_progress')); // 400/1000 = 40%
    }

    public function test_progress_filter_resolves_nested_items_to_root_location(): void
    {
        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Pilot One',
            'corporation_id' => 98000001,
            'scopes' => [],
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                // Container at Jita
                ['item_id' => 100, 'type_id' => 17363, 'quantity' => 1,   'location_id' => 60003760, 'location_type' => 'station'],
                // Tritanium inside the container (root = Jita)
                ['item_id' => 200, 'type_id' => 34,    'quantity' => 500, 'location_id' => 100,       'location_type' => 'item'],
                // 200 units at another station
                ['item_id' => 300, 'type_id' => 34,    'quantity' => 200, 'location_id' => 60004558,  'location_type' => 'station'],
            ]);
        $this->app->instance(EsiService::class, $esi);

        // Filter to only Jita – the container item should count too
        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.progress', $this->program).'?location_ids[]=60003760');

        $response->assertOk();
        $this->assertEquals(50.0, $response->json('data.overall_progress')); // 500/1000 = 50%
    }

    // ── asset-locations endpoint ─────────────────────────────────────

    public function test_asset_locations_returns_distinct_root_locations(): void
    {
        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Pilot One',
            'corporation_id' => 98000001,
            'scopes' => [],
        ]);

        // Seed a known NPC station
        SdeStation::create([
            'station_id' => 60003760,
            'station_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            'solar_system_id' => 30000142,
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 34, 'quantity' => 400, 'location_id' => 60003760, 'location_type' => 'station'],
                // Item inside container at same station – root should still be 60003760
                ['item_id' => 10, 'type_id' => 1000, 'quantity' => 1, 'location_id' => 60003760, 'location_type' => 'station'],
                ['item_id' => 11, 'type_id' => 34,   'quantity' => 100, 'location_id' => 10, 'location_type' => 'item'],
            ]);
        // SdeSolarSystem not seeded; ESI fallback is called but returns null
        $esi->shouldReceive('getSystem')->andReturn(null);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.asset-locations', $this->program));

        $response->assertOk()
            ->assertJsonPath('data.0.location_id', 60003760)
            ->assertJsonPath('data.0.name', 'Jita IV - Moon 4 - Caldari Navy Assembly Plant')
            ->assertJsonPath('data.0.solar_system_name', null); // SdeSolarSystem not seeded

        // Should be only 1 unique location
        $this->assertCount(1, $response->json('data'));
    }

    public function test_asset_locations_includes_player_structures(): void
    {
        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Pilot One',
            'corporation_id' => 98000001,
            'scopes' => [],
        ]);

        $structureId = 1035466617946;

        PlayerOwnedStructure::create([
            'structure_id' => $structureId,
            'name' => 'My Fortizar',
            'owner_id' => 98000001,
            'solar_system_id' => 30000001,
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 34, 'quantity' => 500, 'location_id' => $structureId, 'location_type' => 'item'],
            ]);
        // SdeSolarSystem not seeded; ESI fallback is called but returns null
        $esi->shouldReceive('getSystem')->andReturn(null);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.asset-locations', $this->program));

        $response->assertOk();
        $locations = $response->json('data');
        $this->assertCount(1, $locations);
        $this->assertEquals($structureId, $locations[0]['location_id']);
        $this->assertEquals('My Fortizar', $locations[0]['name']);
    }

    public function test_asset_locations_falls_back_to_esi_for_unknown_structure(): void
    {
        $structureId = 1035466617999;

        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Pilot One',
            'corporation_id' => 98000001,
            'scopes' => ['esi-assets.read_assets.v1', 'esi-universe.read_structures.v1'],
        ]);

        // Structure is NOT in the player_owned_structures table.
        // ESI should be called and returns the structure info.
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 34, 'quantity' => 500, 'location_id' => $structureId, 'location_type' => 'item'],
            ]);
        $esi->shouldReceive('getStructure')
            ->with($structureId, Mockery::type(\App\Models\Character::class))
            ->once()
            ->andReturn(['name' => 'Unknown Fortizar', 'solar_system_id' => 30000142]);
        $esi->shouldReceive('getSystem')
            ->with(30000142)
            ->andReturn(['name' => 'Jita']);
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.asset-locations', $this->program));

        $response->assertOk();
        $locations = $response->json('data');
        $this->assertCount(1, $locations);
        $this->assertEquals($structureId, $locations[0]['location_id']);
        $this->assertEquals('Unknown Fortizar', $locations[0]['name']);
        $this->assertEquals(30000142, $locations[0]['solar_system_id']);
        $this->assertEquals('Jita', $locations[0]['solar_system_name']);
    }

    public function test_asset_locations_structure_not_in_db_and_no_structure_scope(): void
    {
        $structureId = 1035466617888;

        Character::create([
            'character_id' => 12345,
            'user_id' => $this->user->id,
            'name' => 'Pilot One',
            'corporation_id' => 98000001,
            'scopes' => ['esi-assets.read_assets.v1'], // no read_structures scope
        ]);

        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')
            ->andReturn([
                ['item_id' => 1, 'type_id' => 34, 'quantity' => 500, 'location_id' => $structureId, 'location_type' => 'item'],
            ]);
        // getStructure should NOT be called since no character has the structures scope
        $esi->shouldNotReceive('getStructure');
        $this->app->instance(EsiService::class, $esi);

        $response = $this->actingAs($this->user)
            ->getJson(route('api.v1.heavy.build-programs.asset-locations', $this->program));

        $response->assertOk();
        $locations = $response->json('data');
        $this->assertCount(1, $locations);
        // Falls back to generic name when no scope available
        $this->assertEquals("Location #{$structureId}", $locations[0]['name']);
        $this->assertNull($locations[0]['solar_system_name']);
    }

    public function test_asset_locations_requires_authentication(): void
    {
        $response = $this->getJson(route('api.v1.heavy.build-programs.asset-locations', $this->program));
        $response->assertUnauthorized();
    }

    public function test_asset_locations_returns_404_for_other_users_program(): void
    {
        $other = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($other)
            ->getJson(route('api.v1.heavy.build-programs.asset-locations', $this->program));

        $response->assertNotFound();
    }

    public function test_progress_returns_404_for_other_users_program(): void
    {
        $other = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($other)
            ->getJson(route('api.v1.heavy.build-programs.progress', $this->program));

        $response->assertNotFound();
    }

    // ── update-location-filter endpoint ─────────────────────────────

    public function test_update_location_filter_saves_location_ids(): void
    {
        $response = $this->actingAs($this->user)
            ->patchJson(route('api.v1.programs.build-programs.location-filter', $this->program), [
                'location_ids' => [60003760, 1035466617946],
            ]);

        $response->assertOk();

        $this->program->refresh();
        $this->assertEquals([60003760, 1035466617946], $this->program->settings['location_ids']);
    }

    public function test_update_location_filter_clears_location_ids(): void
    {
        // Start with a saved filter
        $this->program->update(['settings' => ['location_ids' => [60003760]]]);

        $response = $this->actingAs($this->user)
            ->patchJson(route('api.v1.programs.build-programs.location-filter', $this->program), [
                'location_ids' => [],
            ]);

        $response->assertOk();

        $this->program->refresh();
        $this->assertEquals([], $this->program->settings['location_ids']);
    }

    public function test_update_location_filter_preserves_other_settings(): void
    {
        $this->program->update(['settings' => ['sections' => [['name' => 'Step 1', 'step_count' => 1]]]]);

        $response = $this->actingAs($this->user)
            ->patchJson(route('api.v1.programs.build-programs.location-filter', $this->program), [
                'location_ids' => [60003760],
            ]);

        $response->assertOk();

        $this->program->refresh();
        $this->assertEquals([60003760], $this->program->settings['location_ids']);
        // Other settings keys must not be lost
        $this->assertArrayHasKey('sections', $this->program->settings);
    }

    public function test_update_location_filter_requires_authentication(): void
    {
        $response = $this->patchJson(route('api.v1.programs.build-programs.location-filter', $this->program), [
            'location_ids' => [60003760],
        ]);
        $response->assertUnauthorized();
    }

    public function test_update_location_filter_returns_404_for_other_users_program(): void
    {
        $other = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $response = $this->actingAs($other)
            ->patchJson(route('api.v1.programs.build-programs.location-filter', $this->program), [
                'location_ids' => [60003760],
            ]);

        $response->assertNotFound();
    }
}
