<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IndustryService;
use App\Services\PlanetaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class DashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => 'cache@example.com',
            'is_active' => true,
        ]);
    }

    public function test_dashboard_uses_cached_stats_without_calling_services(): void
    {
        $user = $this->createUser();

        // Pre-populate cache
        Cache::put("dashboard.industry_stats.{$user->id}", [
            'in_progress' => 7,
            'ready_jobs' => 3,
            'available_slots' => 5,
        ], 1200);

        Cache::put("dashboard.pi_stats.{$user->id}", [
            'total_planets' => 4,
            'expired_extractors' => 1,
            'full_storage' => 2,
        ], 1200);

        // Services should NOT be called when cache is present
        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldNotReceive('getAvailabilityForUser');
        $this->app->instance(IndustryService::class, $industryMock);

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldNotReceive('getPlanetaryDataForUser');
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.industry.in_progress', 7)
            ->assertJsonPath('data.industry.available_slots', 5)
            ->assertJsonPath('data.planetary.full_storage', 2);
    }

    public function test_dashboard_falls_back_to_services_when_cache_empty(): void
    {
        $user = $this->createUser();

        // Ensure no cache exists
        Cache::forget("dashboard.industry_stats.{$user->id}");
        Cache::forget("dashboard.pi_stats.{$user->id}");

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldReceive('getAvailabilityForUser')
            ->with($user->id)
            ->once()
            ->andReturn([
                'totals' => [
                    'manufacturing' => ['total' => 0, 'active' => 0, 'available' => 0],
                    'research' => ['total' => 0, 'active' => 0, 'available' => 0],
                    'reactions' => ['total' => 0, 'active' => 0, 'available' => 0],
                ],
                'characters' => [],
            ]);
        $this->app->instance(IndustryService::class, $industryMock);

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldReceive('getPlanetaryDataForUser')
            ->with($user->id)
            ->once()
            ->andReturn([
                'summary' => ['total_planets' => 0],
                'alerts' => [],
                'characters' => [],
                'outputs' => [],
            ]);
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
    }
}
