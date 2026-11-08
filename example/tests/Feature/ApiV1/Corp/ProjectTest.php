<?php

namespace Tests\Feature\ApiV1\Corp;

use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\LegacyApi\Corp\CorpFixtures;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use CorpFixtures, RefreshDatabase;

    private const SCOPE = 'esi-corporations.read_projects.v1';

    private function fakeEsi(): void
    {
        $this->mock(EsiService::class, function (MockInterface $esi) {
            $esi->shouldReceive('getCorporationProjects')->andReturn([
                $this->project('p-old', 100, 100, '2026-10-01T00:00:00Z', ['initial' => 1000000, 'remaining' => 0], 'Completed'),
                $this->project('p-new', 25, 100, '2026-10-20T00:00:00Z', ['initial' => 400000, 'remaining' => 300000]),
                $this->project('p-mid', 0, 50, '2026-10-10T00:00:00Z'),
            ]);
            $esi->shouldReceive('getCorporationProjectContributors')->with(\Mockery::any(), 98000001, 'p-new')
                ->andReturn([['character_id' => 111, 'name' => 'Alice', 'contributed' => 25]]);
            $esi->shouldReceive('getCorporationProjectContributors')->andReturn([]);
        });
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/corporation/projects')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_projects_paginated_with_summary(): void
    {
        $this->fakeEsi();
        $user = $this->makeUser();
        $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);

        $response = $this->actingAs($user)->getJson('/api/v1/corporation/projects?per_page=2');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'state', 'last_modified', 'progress' => ['current', 'desired'], 'completion_pct', 'is_complete', 'reward', 'reward_earned', 'contributors']],
                'meta' => ['summary' => ['corporation_id', 'corporation_name', 'total_projects', 'completed_projects', 'overall_completion_pct', 'total_reward_earned'], 'pagination'],
            ])
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.*.id', ['p-new', 'p-mid'])
            ->assertJsonPath('data.0.reward', ['initial' => 400000, 'remaining' => 300000])
            ->assertJsonPath('data.0.reward_earned', 100000)
            ->assertJsonPath('data.0.contributors', [['character_id' => 111, 'character_name' => 'Alice', 'contributed' => 25]])
            ->assertJsonPath('data.1.reward', null)
            ->assertJsonPath('meta.summary.total_projects', 3)
            ->assertJsonPath('meta.summary.completed_projects', 1)
            ->assertJsonPath('meta.summary.overall_completion_pct', 50)
            ->assertJsonPath('meta.summary.total_reward_earned', 1100000)
            ->assertJsonMissingPath('meta.freshness');

        $this->actingAs($user)->getJson('/api/v1/corporation/projects?per_page=2&page=2')
            ->assertOk()->assertJsonPath('data.*.id', ['p-old']);
    }

    public function test_bad_pagination_is_422(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);

        $this->actingAs($user)->getJson('/api/v1/corporation/projects?per_page=201')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['per_page']);
    }

    /**
     * @return array<string, array{0: list<array<string, mixed>>}>
     */
    public static function denials(): array
    {
        return [
            'no characters' => [[]],
            'main lacks the projects scope' => [[['scopes' => []]]],
            'only an alt has the scope' => [[['scopes' => []], ['is_main' => false, 'scopes' => [self::SCOPE]]]],
            'main has the scope but no corporation' => [[['corporation_id' => 0, 'scopes' => [self::SCOPE]]]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $characters
     */
    #[DataProvider('denials')]
    public function test_forbidden(array $characters): void
    {
        $this->mock(EsiService::class)->shouldNotReceive('getCorporationProjects');
        $user = $this->makeUser();
        foreach ($characters as $attrs) {
            $this->makeCharacter($user, $attrs);
        }

        $this->actingAs($user)->getJson('/api/v1/corporation/projects')
            ->assertStatus(403)
            ->assertJsonPath('code', 'forbidden');
    }

    public function test_never_exposes_tokens(): void
    {
        $this->fakeEsi();
        $user = $this->makeUser();
        $this->makeCharacter($user, ['scopes' => [self::SCOPE]]);

        $body = $this->actingAs($user)->getJson('/api/v1/corporation/projects')->assertOk()->getContent();

        $this->assertStringNotContainsString('secret-', $body);
        $this->assertStringNotContainsString('token', $body);
    }
}
