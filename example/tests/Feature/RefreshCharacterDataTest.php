<?php

namespace Tests\Feature;

use App\Models\Character;
use App\Models\User;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RefreshCharacterDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_staggered_jobs_and_excludes_inactive_characters(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        [$user, $first] = $this->createUserWithCharacter();
        $second = $first->replicate();
        $second->character_id = 123457;
        $second->save();
        $inactive = $first->replicate();
        $inactive->character_id = 123458;
        $inactive->is_esi_active = false;
        $inactive->save();
        $this->artisan('characters:refresh-data')->assertSuccessful();
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RefreshCharacterProfile::class, 2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RefreshCharacterProfile::class,
            fn ($job) => $job->characterId === 123457 && $job->delay->diffInSeconds(now(), true) >= 299);
    }

    private function createUserWithCharacter(array $overrides = []): array
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@test.com',
            'is_active' => true,
            'last_login_at' => now(),
        ]);

        $character = Character::create(array_merge([
            'character_id' => 123456,
            'user_id' => $user->id,
            'name' => 'Test Pilot',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'alliance_id' => 99000001,
            'alliance_name' => 'Test Alliance',
            'is_main' => true,
            'access_token' => 'test-token',
            'refresh_token' => 'test-refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-location.read_location.v1', 'esi-skills.read_skillqueue.v1', 'esi-skills.read_skills.v1'],
        ], $overrides));

        return [$user, $character];
    }

    public function test_command_updates_cached_location_and_training(): void
    {
        [$user, $character] = $this->createUserWithCharacter();

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterAffiliation')->andReturn([
            'character_id' => 123456,
            'corporation_id' => 98000001,
            'alliance_id' => 99000001,
        ]);
        $esiMock->shouldReceive('getCorporation')->andReturn(['name' => 'Test Corp']);
        $esiMock->shouldReceive('getAlliance')->andReturn(['name' => 'Test Alliance']);
        $esiMock->shouldReceive('fetchPilotCurrentPosition')->andReturn(['solar_system_id' => 30000142]);
        $esiMock->shouldReceive('getSystem')->andReturn(['name' => 'Jita']);
        $esiMock->shouldReceive('fetchPilotTrainingQueue')->andReturn([
            ['queue_position' => 0, 'skill_id' => 3300, 'finished_level' => 5],
        ]);
        $esiMock->shouldReceive('getTypeInfo')->andReturn(['name' => 'Gunnery']);
        $esiMock->shouldReceive('getCharacterSkills')->andReturn(null);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('characters:refresh-data')
            ->assertSuccessful();

        $character->refresh();
        $this->assertEquals('Jita', $character->cached_location);
        $this->assertEquals('Gunnery 5', $character->cached_training);
    }

    public function test_command_handles_null_responses_gracefully(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'alliance_id' => null,
            'alliance_name' => null,
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterAffiliation')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotCurrentPosition')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotTrainingQueue')->andReturn(null);
        $esiMock->shouldReceive('getCharacterSkills')->andReturn(null);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('characters:refresh-data')
            ->assertSuccessful();

        $character->refresh();
        $this->assertNull($character->cached_location);
        $this->assertNull($character->cached_training);
    }

    public function test_command_updates_corporation_and_alliance(): void
    {
        [$user, $character] = $this->createUserWithCharacter();

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterAffiliation')->andReturn([
            'character_id' => 123456,
            'corporation_id' => 98000099,
            'alliance_id' => 99000099,
        ]);
        $esiMock->shouldReceive('getCorporation')->andReturn(['name' => 'New Corp']);
        $esiMock->shouldReceive('getAlliance')->andReturn(['name' => 'New Alliance']);
        $esiMock->shouldReceive('fetchPilotCurrentPosition')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotTrainingQueue')->andReturn(null);
        $esiMock->shouldReceive('getCharacterSkills')->andReturn(null);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('characters:refresh-data')
            ->assertSuccessful();

        $character->refresh();
        $this->assertEquals(98000099, $character->corporation_id);
        $this->assertEquals('New Corp', $character->corporation_name);
        $this->assertEquals(99000099, $character->alliance_id);
        $this->assertEquals('New Alliance', $character->alliance_name);
    }

    public function test_command_updates_training_for_all_characters(): void
    {
        $user = User::create([
            'name' => 'Multi Char User',
            'email' => 'multi@test.com',
            'is_active' => true,
            'last_login_at' => now(),
        ]);

        $char1 = Character::create([
            'character_id' => 111111,
            'user_id' => $user->id,
            'name' => 'Pilot Alpha',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => true,
            'access_token' => 'token-1',
            'refresh_token' => 'refresh-1',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-location.read_location.v1', 'esi-skills.read_skillqueue.v1', 'esi-skills.read_skills.v1'],
        ]);

        $char2 = Character::create([
            'character_id' => 222222,
            'user_id' => $user->id,
            'name' => 'Pilot Beta',
            'corporation_id' => 98000001,
            'corporation_name' => 'Test Corp',
            'is_main' => false,
            'access_token' => 'token-2',
            'refresh_token' => 'refresh-2',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-location.read_location.v1', 'esi-skills.read_skillqueue.v1', 'esi-skills.read_skills.v1'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterAffiliation')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotCurrentPosition')->andReturn(null);
        $esiMock->shouldReceive('getCharacterSkills')->andReturn(null);

        $esiMock->shouldReceive('fetchPilotTrainingQueue')
            ->with(Mockery::on(fn ($c) => $c->character_id === 111111), Mockery::any())
            ->andReturn([
                ['queue_position' => 0, 'skill_id' => 3300, 'finished_level' => 5],
            ]);
        $esiMock->shouldReceive('fetchPilotTrainingQueue')
            ->with(Mockery::on(fn ($c) => $c->character_id === 222222), Mockery::any())
            ->andReturn([
                ['queue_position' => 0, 'skill_id' => 3301, 'finished_level' => 3],
            ]);

        $esiMock->shouldReceive('getTypeInfo')->with(3300)->andReturn(['name' => 'Gunnery']);
        $esiMock->shouldReceive('getTypeInfo')->with(3301)->andReturn(['name' => 'Navigation']);

        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('characters:refresh-data')
            ->assertSuccessful();

        $char1->refresh();
        $char2->refresh();
        $this->assertEquals('Gunnery 5', $char1->cached_training);
        $this->assertEquals('Navigation 3', $char2->cached_training);
    }

    public function test_command_sets_is_director_ceo_when_character_has_director_role(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'scopes' => ['esi-characters.read_corporation_roles.v1'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterAffiliation')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotCurrentPosition')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotTrainingQueue')->andReturn(null);
        $esiMock->shouldReceive('getCharacterSkills')->andReturn(null);
        $esiMock->shouldReceive('getCharacterRoles')->andReturn([
            'roles' => ['Director', 'Accountant'],
        ]);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('characters:refresh-data')->assertSuccessful();

        $character->refresh();
        $this->assertTrue($character->is_director_ceo);
        $this->assertEquals(['Director', 'Accountant'], $character->roles);
    }

    public function test_command_clears_is_director_ceo_when_director_role_removed(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'scopes' => ['esi-characters.read_corporation_roles.v1'],
            'is_director_ceo' => true,
            'roles' => ['Director'],
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterAffiliation')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotCurrentPosition')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotTrainingQueue')->andReturn(null);
        $esiMock->shouldReceive('getCharacterSkills')->andReturn(null);
        $esiMock->shouldReceive('getCharacterRoles')->andReturn([
            'roles' => ['Accountant'],
        ]);
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('characters:refresh-data')->assertSuccessful();

        $character->refresh();
        $this->assertFalse($character->is_director_ceo);
        $this->assertEquals(['Accountant'], $character->roles);
    }

    public function test_command_skips_role_refresh_without_roles_scope(): void
    {
        [$user, $character] = $this->createUserWithCharacter([
            'scopes' => ['esi-location.read_location.v1', 'esi-skills.read_skillqueue.v1', 'esi-skills.read_skills.v1'],
            'is_director_ceo' => true,
        ]);

        $esiMock = Mockery::mock(EsiService::class);
        $esiMock->shouldReceive('getCharacterAffiliation')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotCurrentPosition')->andReturn(null);
        $esiMock->shouldReceive('fetchPilotTrainingQueue')->andReturn(null);
        $esiMock->shouldReceive('getCharacterSkills')->andReturn(null);
        $esiMock->shouldNotReceive('getCharacterRoles');
        $this->app->instance(EsiService::class, $esiMock);

        $this->artisan('characters:refresh-data')->assertSuccessful();

        // is_director_ceo should remain unchanged since we couldn't check roles
        $character->refresh();
        $this->assertTrue($character->is_director_ceo);
    }
}
