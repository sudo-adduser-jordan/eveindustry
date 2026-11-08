<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class ProjectContributorsTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    private const SCOPE = 'esi-corporations.read_projects.v1';

    private function fakeEsi(): void
    {
        $this->mock(EsiService::class, function (MockInterface $esi) {
            $esi->shouldReceive('getCorporationProjects')->andReturn([
                // 1000 ISK reward over 100 units = 10 ISK a unit.
                $this->project('p-1', 100, 100, '2026-10-01T00:00:00Z', ['initial' => 1000, 'remaining' => 0], 'Completed'),
                $this->project('p-2', 50, 50, '2026-10-15T00:00:00Z', ['initial' => 500, 'remaining' => 0], 'Closed'),
                // Still active, so not on the leaderboard.
                $this->project('p-3', 5, 50, '2026-10-16T00:00:00Z', ['initial' => 500, 'remaining' => 400]),
                // No reward.
                $this->project('p-4', 10, 10, '2026-10-17T00:00:00Z', null, 'Completed'),
            ]);
            $esi->shouldReceive('getCorporationProjectContributors')->with(\Mockery::any(), 98000001, 'p-1')
                ->andReturn([
                    ['character_id' => 111, 'name' => 'Alice', 'contributed' => 60],
                    ['character_id' => 222, 'name' => 'Bob', 'contributed' => 40],
                ]);
            $esi->shouldReceive('getCorporationProjectContributors')->with(\Mockery::any(), 98000001, 'p-2')
                ->andReturn([['character_id' => 222, 'name' => 'Bob', 'contributed' => 50]]);
            $esi->shouldReceive('getCorporationProjectContributors')->andReturn([]);
        });
    }

    private function member()
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);

        return $user;
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/corporation/projects/contributors')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->postJson('/api/v1/corporation/projects/refresh')->assertStatus(401);
    }

    public function test_leaderboard_ranks_by_attributed_isk(): void
    {
        $this->fakeEsi();

        $this->actingAs($this->member())->getJson('/api/v1/corporation/projects/contributors')
            ->assertOk()
            ->assertJsonPath('meta.summary.corporation_name', 'Test Corp')
            ->assertJsonPath('meta.summary.total_rewarded_projects', 2)
            ->assertJsonPath('meta.summary.total_attributed_isk', 1500)
            ->assertJsonPath('data.*.character_name', ['Bob', 'Alice'])
            ->assertJsonPath('data.0.attributed_isk', 900)
            ->assertJsonPath('data.0.project_count', 2)
            ->assertJsonPath('data.0.attributed_pct', 60)
            ->assertJsonPath('data.0.projects.0.name', 'Project p-1')
            ->assertJsonPath('data.1.attributed_isk', 600)
            ->assertJsonPath('meta.pagination.total', 2);
    }

    public function test_filters_by_project_name_and_date(): void
    {
        $this->fakeEsi();
        $user = $this->member();

        $this->actingAs($user)->getJson('/api/v1/corporation/projects/contributors?project_name=p-2')
            ->assertOk()
            ->assertJsonPath('meta.summary.project_name', 'p-2')
            ->assertJsonPath('data.*.character_name', ['Bob']);

        $this->actingAs($user)->getJson('/api/v1/corporation/projects/contributors?date_from=2026-10-10&date_to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('meta.summary.date_from', '2026-10-10')
            ->assertJsonPath('data.*.character_name', ['Bob']);
    }

    public function test_rejects_a_bad_date(): void
    {
        $this->actingAs($this->member())->getJson('/api/v1/corporation/projects/contributors?date_from=yesterday')
            ->assertStatus(422)->assertJsonValidationErrors(['date_from']);
    }

    public function test_forbidden_without_the_projects_scope(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $this->actingAs($user)->getJson('/api/v1/corporation/projects/contributors')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->actingAs($user)->postJson('/api/v1/corporation/projects/refresh')
            ->assertForbidden()->assertJsonPath('code', 'forbidden');
    }

    public function test_projects_403_names_the_scope(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $this->actingAs($user)->getJson('/api/v1/corporation/projects')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'esi-corporations.read_projects.v1'));
    }

    public function test_refresh_clears_the_project_caches(): void
    {
        $this->mock(EsiService::class, function (MockInterface $esi) {
            $esi->shouldReceive('clearCorporationProjectsCache')->once()->with(98000001);
        });

        $this->actingAs($this->member())->postJson('/api/v1/corporation/projects/refresh')->assertNoContent();
    }
}
