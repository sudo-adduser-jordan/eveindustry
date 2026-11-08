<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IndustryService;
use App\Services\PlanetaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DashboardStatsTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'is_active' => true,
        ], $overrides));
    }

    public function test_dashboard_displays_industry_stat_boxes(): void
    {
        $user = $this->createUser();

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldReceive('getAvailabilityForUser')
            ->with($user->id)
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
            ->andReturn([
                'summary' => ['total_planets' => 2, 'total_extractors' => 4, 'total_factories' => 6, 'total_characters' => 1],
                'alerts' => [
                    ['type' => 'extractor_expired', 'severity' => 'warning', 'message' => 'Extractor expired'],
                ],
                'characters' => [
                    [
                        'planets' => [
                            [
                                'pins' => [
                                    ['storage' => ['usage_percent' => 95]],
                                ],
                            ],
                        ],
                    ],
                ],
                'outputs' => [],
            ]);
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.industry.in_progress', 6)
            ->assertJsonPath('data.industry.ready_jobs', 2)
            ->assertJsonPath('data.industry.available_slots', 6)
            ->assertJsonPath('data.planetary.expired_extractors', 1)
            ->assertJsonPath('data.planetary.full_storage', 1);
    }

    public function test_dashboard_displays_saved_plans(): void
    {
        $user = $this->createUser();

        $user->reactionPlans()->create([
            'name' => 'My Reaction Plan',
            'entries' => [],
        ]);

        $user->inventionPlans()->create([
            'name' => 'My Invention Plan',
            't2_type_id' => 12345,
            't2_type_name' => 'Test T2 Item',
        ]);

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
            ->andReturn(['summary' => ['total_planets' => 0], 'alerts' => [], 'characters' => [], 'outputs' => []]);
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.reaction_plans.0.name', 'My Reaction Plan')
            ->assertJsonPath('data.invention_plans.0.name', 'My Invention Plan')
            ->assertJsonPath('data.invention_plans.0.t2_type_name', 'Test T2 Item');
    }

    public function test_dashboard_handles_service_failures_gracefully(): void
    {
        $user = $this->createUser();

        $industryMock = Mockery::mock(IndustryService::class);
        $industryMock->shouldReceive('getAvailabilityForUser')
            ->andThrow(new \RuntimeException('ESI API error'));
        $this->app->instance(IndustryService::class, $industryMock);

        $planetaryMock = Mockery::mock(PlanetaryService::class);
        $planetaryMock->shouldReceive('getPlanetaryDataForUser')
            ->andThrow(new \RuntimeException('ESI API error'));
        $this->app->instance(PlanetaryService::class, $planetaryMock);

        $response = $this->actingAs($user)->getJson('/api/v1/dashboard');

        $response->assertStatus(200);
        $response->assertJsonPath('data.industry.in_progress', 0)
            ->assertJsonPath('data.planetary.total_planets', 0);
    }
}
