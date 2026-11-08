<?php

namespace Tests\Feature;

use App\Jobs\RefreshDashboardStats;
use App\Models\Character;
use App\Models\User;
use App\Services\IndustryService;
use App\Services\PlanetaryService;
use App\Services\RefreshEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RefreshEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $id, array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'User '.$id, 'email' => $id.'@example.com',
            'is_active' => true, 'last_login_at' => now(),
        ], $overrides));
    }

    private function pilot(User $user, int $id, array $overrides = []): Character
    {
        return Character::create(array_merge([
            'character_id' => $id, 'user_id' => $user->id, 'name' => 'Pilot '.$id,
            'is_esi_active' => true, 'corporation_id' => 98,
            'scopes' => RefreshEligibility::INDUSTRY_SCOPES,
        ], $overrides));
    }

    public function test_recent_enabled_users_need_active_scoped_characters_for_each_dataset(): void
    {
        $eligible = $this->user(1);
        $this->pilot($eligible, 1);
        $dormant = $this->user(2, ['last_login_at' => now()->subDays(8)]);
        $this->pilot($dormant, 2);
        $disabled = $this->user(3, ['is_active' => false]);
        $this->pilot($disabled, 3);
        $inactive = $this->user(4);
        $this->pilot($inactive, 4, ['is_esi_active' => false]);
        $missingScope = $this->user(5);
        $this->pilot($missingScope, 5, ['scopes' => ['esi-industry.read_character_jobs.v1']]);
        $this->user(6);
        $piOnly = $this->user(7);
        $this->pilot($piOnly, 7, ['scopes' => RefreshEligibility::PI_SCOPES]);

        $this->assertSame([$eligible->id], RefreshEligibility::users('industry')->pluck('id')->all());
        $this->assertSame([$eligible->id, $piOnly->id], RefreshEligibility::users('dashboard')->orderBy('id')->pluck('id')->all());
        $this->assertSame([$piOnly->id], RefreshEligibility::users('pi')->pluck('id')->all());
    }

    public function test_industry_collector_requires_both_scope_and_factory_manager_role(): void
    {
        $user = $this->user(1);
        $character = $this->pilot($user, 1, ['roles' => ['Factory_Manager']]);
        $this->assertFalse(RefreshEligibility::corporation($character, 98, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager']));
        $character->scopes = ['esi-industry.read_corporation_jobs.v1'];
        $this->assertTrue(RefreshEligibility::corporation($character, 98, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager']));
        $character->roles = ['Director'];
        $this->assertFalse(RefreshEligibility::corporation($character, 98, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager']));
        $character->roles = ['Factory_Manager'];
        $this->assertFalse(RefreshEligibility::corporation($character, 99, 'esi-industry.read_corporation_jobs.v1', ['Factory_Manager']));
    }

    public function test_dashboard_rechecks_scope_changes_at_execution(): void
    {
        $user = $this->user(1);
        $pilot = $this->pilot($user, 1);
        $job = new RefreshDashboardStats($user->id);
        $pilot->update(['scopes' => []]);
        $industry = Mockery::mock(IndustryService::class);
        $industry->shouldNotReceive('getAvailabilityForUser');
        $planetary = Mockery::mock(PlanetaryService::class);
        $planetary->shouldNotReceive('getPlanetaryDataForUser');
        $job->handle($industry, $planetary);
    }

    public function test_one_collector_is_used_for_all_installers_in_a_corporation(): void
    {
        $user = $this->user(1);
        $installer = $this->pilot($user, 1);
        $collector = $this->pilot($user, 2, ['roles' => ['Factory_Manager'], 'scopes' => array_merge(
            RefreshEligibility::INDUSTRY_SCOPES, ['esi-industry.read_corporation_jobs.v1']
        )]);
        $esi = Mockery::mock(\App\Services\EsiService::class);
        $esi->shouldReceive('getCharacterIndustryJobs')->twice()->andReturn([]);
        $esi->shouldReceive('getCorporationIndustryJobs')->once()
            ->with(Mockery::on(fn ($pilot) => $pilot->character_id === $collector->character_id), 98, false)
            ->andReturn([
                ['job_id' => 1, 'installer_id' => 1, 'status' => 'active'],
                ['job_id' => 2, 'installer_id' => 2, 'status' => 'active'],
            ]);
        $service = new IndustryService($esi);
        $method = new \ReflectionMethod($service, 'getActiveJobs');
        $this->assertSame([1], array_values(array_column($method->invoke($service, $installer), 'job_id')));
        $this->assertSame([2], array_values(array_column($method->invoke($service, $collector), 'job_id')));
    }

    public function test_failed_industry_refresh_keeps_previous_complete_slot_cache(): void
    {
        $user = $this->user(1);
        $pilot = $this->pilot($user, 1);
        $esi = Mockery::mock(\App\Services\EsiService::class);
        $esi->shouldReceive('getCharacterSkills')->once()->andReturn(['skills' => []]);
        $esi->shouldReceive('getCharacterIndustryJobs')->once()->andReturn(null);
        $service = new IndustryService($esi);
        $key = (new \ReflectionMethod($service, 'slotsCacheKey'))->invoke($service, $pilot);
        \Illuminate\Support\Facades\Cache::put($key, ['previous' => 'complete'], 1200);
        try {
            $service->refreshForUser($user->id);
            $this->fail('A failed source must abort replacement');
        } catch (\RuntimeException $e) {
            $this->assertSame(['previous' => 'complete'], \Illuminate\Support\Facades\Cache::get($key));
        }
    }

    public function test_revoking_another_collector_changes_the_installers_cache_identity(): void
    {
        $user = $this->user(1);
        $installer = $this->pilot($user, 1);
        $collector = $this->pilot($user, 2, ['roles' => ['Factory_Manager'], 'scopes' => ['esi-industry.read_corporation_jobs.v1']]);
        $service = new IndustryService(Mockery::mock(\App\Services\EsiService::class));
        $method = new \ReflectionMethod($service, 'slotsCacheKey');
        $before = $method->invoke($service, $installer);
        $collector->update(['roles' => []]);
        $this->assertNotSame($before, $method->invoke($service, $installer));
    }
}
