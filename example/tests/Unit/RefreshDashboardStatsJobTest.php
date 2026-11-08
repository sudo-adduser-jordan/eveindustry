<?php

namespace Tests\Unit;

use App\Jobs\RefreshDashboardStats;
use App\Models\User;
use App\Services\IndustryService;
use App\Services\PlanetaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class RefreshDashboardStatsJobTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => true,
            'last_login_at' => now(),
        ]);
        \App\Models\Character::create([
            'character_id' => 12345, 'user_id' => $user->id, 'name' => 'Eligible Pilot', 'corporation_id' => 98,
            'is_esi_active' => true,
            'scopes' => array_merge(\App\Services\RefreshEligibility::INDUSTRY_SCOPES, \App\Services\RefreshEligibility::PI_SCOPES),
        ]);

        return $user;
    }

    public function test_job_caches_industry_stats(): void
    {
        $user = $this->createUser();

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldReceive('getAvailabilityForUser')
            ->with($user->id)
            ->once()
            ->andReturn([
                'totals' => [
                    'manufacturing' => ['total' => 5, 'active' => 3, 'available' => 2],
                    'research' => ['total' => 3, 'active' => 1, 'available' => 2],
                    'reactions' => ['total' => 4, 'active' => 2, 'available' => 2],
                ],
                'characters' => [
                    [
                        'manufacturing' => ['jobs' => [
                            ['ready_for_delivery' => true],
                            ['ready_for_delivery' => false],
                        ]],
                        'research' => ['jobs' => []],
                        'reactions' => ['jobs' => [
                            ['ready_for_delivery' => true],
                        ]],
                    ],
                ],
            ]);
        $this->app->instance(IndustryService::class, $industryMock);

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldReceive('getPlanetaryDataForUser')
            ->with($user->id)
            ->once()
            ->andReturn(['summary' => ['total_planets' => 0], 'alerts' => [], 'characters' => [], 'outputs' => []]);
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $job = new RefreshDashboardStats($user->id);
        $job->handle($industryMock, $planetaryMock);

        $cached = Cache::get("dashboard.industry_stats.{$user->id}");
        $this->assertNotNull($cached);
        $this->assertEquals(6, $cached['in_progress']);
        $this->assertEquals(2, $cached['ready_jobs']);
        $this->assertEquals(6, $cached['available_slots']);
    }

    public function test_job_caches_pi_stats(): void
    {
        $user = $this->createUser();

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldReceive('getAvailabilityForUser')
            ->andReturn(['totals' => [
                'manufacturing' => ['total' => 0, 'active' => 0, 'available' => 0],
                'research' => ['total' => 0, 'active' => 0, 'available' => 0],
                'reactions' => ['total' => 0, 'active' => 0, 'available' => 0],
            ], 'characters' => []]);
        $this->app->instance(IndustryService::class, $industryMock);

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldReceive('getPlanetaryDataForUser')
            ->with($user->id)
            ->once()
            ->andReturn([
                'summary' => ['total_planets' => 3],
                'alerts' => [
                    ['type' => 'extractor_expired', 'severity' => 'warning'],
                    ['type' => 'extractor_expired', 'severity' => 'warning'],
                ],
                'characters' => [
                    [
                        'planets' => [
                            [
                                'pins' => [
                                    ['storage' => ['usage_percent' => 95]],
                                    ['storage' => ['usage_percent' => 50]],
                                ],
                            ],
                        ],
                    ],
                ],
                'outputs' => [],
            ]);
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $job = new RefreshDashboardStats($user->id);
        $job->handle($industryMock, $planetaryMock);

        $cached = Cache::get("dashboard.pi_stats.{$user->id}");
        $this->assertNotNull($cached);
        $this->assertEquals(3, $cached['total_planets']);
        $this->assertEquals(2, $cached['expired_extractors']);
        $this->assertEquals(1, $cached['full_storage']);
    }

    public function test_job_handles_industry_failure_gracefully(): void
    {
        $user = $this->createUser();

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldReceive('getAvailabilityForUser')
            ->andThrow(new \RuntimeException('ESI error'));
        $this->app->instance(IndustryService::class, $industryMock);

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldReceive('getPlanetaryDataForUser')
            ->andReturn(['summary' => ['total_planets' => 1], 'alerts' => [], 'characters' => [], 'outputs' => []]);
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $job = new RefreshDashboardStats($user->id);
        try {
            $job->handle($industryMock, $planetaryMock);
            $this->fail('A failed dataset must reach the queue retry handler');
        } catch (\RuntimeException $e) {
            $this->assertSame('ESI error', $e->getMessage());
        }

        // Industry stats should NOT be cached (failure)
        $this->assertNull(Cache::get("dashboard.industry_stats.{$user->id}"));
        // PI stats should still be cached (success)
        $piCached = Cache::get("dashboard.pi_stats.{$user->id}");
        $this->assertNotNull($piCached);
        $this->assertEquals(1, $piCached['total_planets']);
    }

    public function test_job_handles_pi_failure_gracefully(): void
    {
        $user = $this->createUser();

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldReceive('getAvailabilityForUser')
            ->andReturn(['totals' => [
                'manufacturing' => ['total' => 1, 'active' => 1, 'available' => 0],
                'research' => ['total' => 0, 'active' => 0, 'available' => 0],
                'reactions' => ['total' => 0, 'active' => 0, 'available' => 0],
            ], 'characters' => []]);
        $this->app->instance(IndustryService::class, $industryMock);

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldReceive('getPlanetaryDataForUser')
            ->andThrow(new \RuntimeException('ESI error'));
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $job = new RefreshDashboardStats($user->id);
        try {
            $job->handle($industryMock, $planetaryMock);
            $this->fail('A failed dataset must reach the queue retry handler');
        } catch (\RuntimeException $e) {
            $this->assertSame('ESI error', $e->getMessage());
        }

        // Industry stats should be cached
        $industryCached = Cache::get("dashboard.industry_stats.{$user->id}");
        $this->assertNotNull($industryCached);
        $this->assertEquals(1, $industryCached['in_progress']);
        // PI stats should NOT be cached (failure)
        $this->assertNull(Cache::get("dashboard.pi_stats.{$user->id}"));
    }

    public function test_job_skips_recompute_when_cache_is_fresh(): void
    {
        $user = $this->createUser();

        // Fresh cache entries computed just now should short-circuit recompute.
        Cache::put("dashboard.industry_stats.{$user->id}", ['computed_at' => now()->timestamp], 1200);
        Cache::put("dashboard.pi_stats.{$user->id}", ['computed_at' => now()->timestamp], 1200);

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldNotReceive('getAvailabilityForUser');

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldNotReceive('getPlanetaryDataForUser');

        $job = new RefreshDashboardStats($user->id);
        $job->handle($industryMock, $planetaryMock);

        $this->assertTrue(true);
    }

    public function test_job_recomputes_when_cache_is_stale(): void
    {
        $user = $this->createUser();

        // Stale cache entries (older than the freshness window) must recompute.
        Cache::put("dashboard.industry_stats.{$user->id}", ['computed_at' => now()->subHour()->timestamp], 1200);
        Cache::put("dashboard.pi_stats.{$user->id}", ['computed_at' => now()->subHour()->timestamp], 1200);

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldReceive('getAvailabilityForUser')
            ->with($user->id)
            ->once()
            ->andReturn(['totals' => [
                'manufacturing' => ['total' => 0, 'active' => 0, 'available' => 0],
                'research' => ['total' => 0, 'active' => 0, 'available' => 0],
                'reactions' => ['total' => 0, 'active' => 0, 'available' => 0],
            ], 'characters' => []]);

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldReceive('getPlanetaryDataForUser')
            ->with($user->id)
            ->once()
            ->andReturn(['summary' => ['total_planets' => 0], 'alerts' => [], 'characters' => [], 'outputs' => []]);

        $job = new RefreshDashboardStats($user->id);
        $job->handle($industryMock, $planetaryMock);

        $this->assertArrayHasKey('computed_at', Cache::get("dashboard.industry_stats.{$user->id}"));
    }

    public function test_on_timeout_logs_warning(): void
    {
        $user = $this->createUser();

        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->with("RefreshDashboardStats: Job timed out for user {$user->id}, will be retried.");

        $job = new RefreshDashboardStats($user->id);
        $job->onTimeout();
    }
}
