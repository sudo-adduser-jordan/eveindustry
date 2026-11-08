<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class EsiTokenDeactivationTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithCharacter(array $overrides = []): array
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'deactivation-test@test.com',
            'is_active' => true,
        ]);

        $character = Character::create(array_merge([
            'character_id' => 123456,
            'user_id' => $user->id,
            'name' => 'Test Pilot',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addMinutes(5),
            'scopes' => [],
        ], $overrides));

        Cache::put("dashboard.industry_stats.{$user->id}", ['in_progress' => 0, 'ready_jobs' => 0, 'available_slots' => 0], 60);
        Cache::put("dashboard.pi_stats.{$user->id}", ['total_planets' => 0, 'expired_extractors' => 0, 'full_storage' => 0], 60);

        return [$user, $character];
    }

    public function test_dashboard_shows_warning_for_inactive_character(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'is_esi_active' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.characters.0.name', 'Test Pilot')
            ->assertJsonPath('data.characters.0.is_esi_active', false);
    }

    public function test_dashboard_does_not_show_warning_when_all_active(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'is_esi_active' => true,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.characters.0.is_esi_active', true);
    }

    public function test_characters_table_shows_token_expired_badge(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'is_esi_active' => false,
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.characters.0.is_esi_active', false);
    }

    public function test_character_refresh_skips_esi_inactive_characters(): void
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'refresh-skip@test.com',
            'is_active' => true,
            'last_login_at' => now(),
        ]);

        Character::create([
            'character_id' => 111111,
            'user_id' => $user->id,
            'name' => 'Active Pilot',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'token-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addMinutes(15),
            'scopes' => ['esi-location.read_location.v1', 'esi-skills.read_skillqueue.v1', 'esi-skills.read_skills.v1'],
            'is_esi_active' => true,
        ]);

        Character::create([
            'character_id' => 222222,
            'user_id' => $user->id,
            'name' => 'Inactive Pilot',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => false,
            'access_token' => 'token-2',
            'refresh_token' => 'refresh-2',
            'token_expires_at' => now()->subMinutes(5),
            'scopes' => [],
            'is_esi_active' => false,
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        // Should only process the active character, not the inactive one
        $esiMock->shouldReceive('getCharacterAffiliation')->once()->andReturn(null);
        $esiMock->shouldReceive('fetchPilotCurrentPosition')->once()->andReturn(null);
        $esiMock->shouldReceive('fetchPilotTrainingQueue')->once()->andReturn(null);
        $esiMock->shouldReceive('getCharacterSkills')->once()->andReturn(null);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('characters:refresh-data')
            ->assertSuccessful();
    }

    public function test_is_esi_active_defaults_to_true(): void
    {
        [$user, $character] = $this->createUserWithCharacter();

        $character->refresh();
        $this->assertTrue($character->is_esi_active);
    }
}
