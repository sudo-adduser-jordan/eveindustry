<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\User;
use App\Services\CorporationProjectsService;
use App\Services\EsiService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CorporationProjectsServiceTest extends TestCase
{
    use RefreshDatabase;

    private CorporationProjectsService $service;

    private $esiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esiMock = Mockery::mock(EsiService::class);
        $this->service = new CorporationProjectsService($this->esiMock);
    }

    // ---------------------------------------------------------------------------
    // buildAnalytics — metrics
    // ---------------------------------------------------------------------------

    public function test_build_analytics_counts_completed_projects(): void
    {
        $character = $this->makeCharacter();

        $projects = [
            $this->makeProject('p1', current: 100, desired: 100),
            $this->makeProject('p2', current: 50, desired: 100),
            $this->makeProject('p3', current: 0, desired: 100),
        ];

        $this->esiMock->shouldReceive('getCorporationProjectContributors')->andReturn([]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertEquals(3, $result['total_projects']);
        $this->assertEquals(1, $result['completed_projects']);
        $this->assertEquals(1, $result['in_progress_projects']);
    }

    public function test_build_analytics_computes_completion_pct(): void
    {
        $character = $this->makeCharacter();

        $projects = [$this->makeProject('p1', current: 25, desired: 100)];

        $this->esiMock->shouldReceive('getCorporationProjectContributors')->andReturn([]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertEquals(25.0, $result['projects'][0]['completion_pct']);
        $this->assertFalse($result['projects'][0]['is_complete']);
    }

    public function test_build_analytics_marks_complete_when_current_equals_desired(): void
    {
        $character = $this->makeCharacter();

        $projects = [$this->makeProject('p1', current: 500, desired: 500)];

        $this->esiMock->shouldReceive('getCorporationProjectContributors')->andReturn([]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertTrue($result['projects'][0]['is_complete']);
        $this->assertEquals(100.0, $result['projects'][0]['completion_pct']);
    }

    public function test_build_analytics_aggregates_rewards(): void
    {
        $character = $this->makeCharacter();

        $projects = [
            array_merge($this->makeProject('p1', current: 100, desired: 100), [
                'reward' => ['initial' => 1_000_000, 'remaining' => 0],
            ]),
            array_merge($this->makeProject('p2', current: 50, desired: 100), [
                'reward' => ['initial' => 2_000_000, 'remaining' => 1_000_000],
            ]),
        ];

        $this->esiMock->shouldReceive('getCorporationProjectContributors')->andReturn([]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertEquals(3_000_000, $result['total_reward_initial']);
        $this->assertEquals(1_000_000, $result['total_reward_remaining']);
        $this->assertEquals(2_000_000, $result['total_reward_earned']);
    }

    public function test_build_analytics_handles_project_without_reward(): void
    {
        $character = $this->makeCharacter();

        $projects = [$this->makeProject('p1', current: 100, desired: 100)];

        $this->esiMock->shouldReceive('getCorporationProjectContributors')->andReturn([]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertEquals(0, $result['total_reward_initial']);
        $this->assertEquals(0, $result['projects'][0]['reward_earned']);
    }

    public function test_build_analytics_overall_completion_pct(): void
    {
        $character = $this->makeCharacter();

        $projects = [
            $this->makeProject('p1', current: 50, desired: 100),
            $this->makeProject('p2', current: 50, desired: 100),
        ];

        $this->esiMock->shouldReceive('getCorporationProjectContributors')->andReturn([]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertEquals(50.0, $result['overall_completion_pct']);
        $this->assertEquals(100, $result['total_progress']);
        $this->assertEquals(200, $result['total_desired']);
    }

    // ---------------------------------------------------------------------------
    // buildAnalytics — contributors
    // ---------------------------------------------------------------------------

    public function test_build_analytics_attaches_contributors_to_each_project(): void
    {
        $character = $this->makeCharacter();

        $projects = [
            $this->makeProject('p1', current: 50, desired: 100),
            $this->makeProject('p2', current: 100, desired: 100),
        ];

        $this->esiMock
            ->shouldReceive('getCorporationProjectContributors')
            ->with($character, $character->corporation_id, 'p1')
            ->once()
            ->andReturn([['character_id' => 111, 'quantity' => 30], ['character_id' => 222, 'quantity' => 20]]);

        $this->esiMock
            ->shouldReceive('getCorporationProjectContributors')
            ->with($character, $character->corporation_id, 'p2')
            ->once()
            ->andReturn([['character_id' => 333, 'quantity' => 100]]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertCount(2, $result['projects'][0]['contributors']);
        $this->assertCount(1, $result['projects'][1]['contributors']);
    }

    public function test_build_analytics_uses_esi_name_from_contributor(): void
    {
        $character = $this->makeCharacter();

        $projects = [$this->makeProject('p1', current: 50, desired: 100)];

        $this->esiMock
            ->shouldReceive('getCorporationProjectContributors')
            ->once()
            ->andReturn([['character_id' => 99999, 'name' => 'Eve Player', 'quantity' => 50]]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertEquals('Eve Player', $result['projects'][0]['contributors'][0]['character_name']);
    }

    public function test_build_analytics_sets_null_name_when_esi_provides_no_name(): void
    {
        $character = $this->makeCharacter();

        $projects = [$this->makeProject('p1', current: 50, desired: 100)];

        $this->esiMock
            ->shouldReceive('getCorporationProjectContributors')
            ->once()
            ->andReturn([['character_id' => 88888, 'quantity' => 10]]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertNull($result['projects'][0]['contributors'][0]['character_name']);
    }

    public function test_build_analytics_handles_empty_contributors_gracefully(): void
    {
        $character = $this->makeCharacter();

        $projects = [$this->makeProject('p1', current: 50, desired: 100)];

        $this->esiMock
            ->shouldReceive('getCorporationProjectContributors')
            ->once()
            ->andReturn([]);

        $result = $this->service->buildAnalytics($projects, $character);

        $this->assertEmpty($result['projects'][0]['contributors']);
    }

    // ---------------------------------------------------------------------------
    // getProjectsForUser — returns all projects without filtering
    // ---------------------------------------------------------------------------

    public function test_get_projects_for_user_returns_all_projects(): void
    {
        $user = $this->makeUser('allprojects@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [
            $this->makeProject('p1', current: 10, desired: 10, lastModified: '2020-01-01T00:00:00Z'),
            $this->makeProject('p2', current: 5, desired: 10, lastModified: '2023-06-15T00:00:00Z'),
            $this->makeProject('p3', current: 10, desired: 10, lastModified: '2026-02-20T00:00:00Z'),
        ];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        $this->esiMock->shouldReceive('getCorporationProjectContributors')->andReturn([]);

        $result = $this->service->getProjectsForUser($user->id);

        $this->assertCount(3, $result['projects']);
    }

    public function test_get_projects_for_user_returns_empty_when_no_access(): void
    {
        $user = $this->makeUser('noaccess@example.com');
        $this->makeCharacterForUser($user->id, withScope: false);

        $result = $this->service->getProjectsForUser($user->id);

        $this->assertEmpty($result['projects']);
        $this->assertNull($result['corporation_name']);
    }

    // ---------------------------------------------------------------------------
    // getUserProjectsCharacters
    // ---------------------------------------------------------------------------

    public function test_get_user_projects_characters_returns_only_characters_with_scope(): void
    {
        $user = $this->makeUser('withscope@example.com');
        $withScope = $this->makeCharacterForUser($user->id, withScope: true, characterId: 11111, corpId: 1001, isMain: true);
        $this->makeCharacterForUser($user->id, withScope: false, characterId: 22222, corpId: 1002, isMain: false);

        $result = $this->service->getUserProjectsCharacters($user->id);

        $this->assertCount(1, $result);
        $this->assertEquals($withScope->character_id, $result->first()->character_id);
    }

    public function test_get_user_projects_characters_returns_empty_when_no_characters_have_scope(): void
    {
        $user = $this->makeUser('noscope@example.com');
        $this->makeCharacterForUser($user->id, withScope: false, characterId: 55555, corpId: 1003, isMain: true);

        $result = $this->service->getUserProjectsCharacters($user->id);

        $this->assertCount(0, $result);
    }

    public function test_get_user_projects_characters_ignores_non_main_character_with_scope(): void
    {
        $user = $this->makeUser('nonmain@example.com');
        // Alt character has the scope but is NOT the main — must be ignored
        $this->makeCharacterForUser($user->id, withScope: true, characterId: 77777, corpId: 1004, isMain: false);

        $result = $this->service->getUserProjectsCharacters($user->id);

        $this->assertCount(0, $result);
    }

    // ---------------------------------------------------------------------------
    // emptyResult
    // ---------------------------------------------------------------------------

    public function test_empty_result_returns_zero_counts(): void
    {
        $result = $this->service->emptyResult();

        $this->assertEquals(0, $result['total_projects']);
        $this->assertEquals(0, $result['completed_projects']);
        $this->assertEquals(0, $result['total_reward_earned']);
        $this->assertEmpty($result['projects']);
    }

    // ---------------------------------------------------------------------------
    // buildContributorLeaderboard
    // ---------------------------------------------------------------------------

    public function test_leaderboard_excludes_projects_without_reward(): void
    {
        $user = $this->makeUser('lb-noreward@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [
            $this->makeProject('p1', current: 100, desired: 100, state: 'Closed'),                           // no reward
            array_merge($this->makeProject('p2', current: 100, desired: 100, state: 'Closed'),
                ['reward' => ['initial' => 1_000_000, 'remaining' => 0]]),           // has reward
        ];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        $this->esiMock->shouldReceive('getCorporationProjectContributors')
            ->with(Mockery::any(), Mockery::any(), 'p2')->once()
            ->andReturn([['character_id' => 111, 'name' => 'Alice', 'contributed' => 100]]);

        $result = $this->service->buildContributorLeaderboard($user->id);

        $this->assertEquals(1, $result['total_rewarded_projects']);
    }

    public function test_leaderboard_attributes_isk_by_quantity(): void
    {
        $user = $this->makeUser('lb-isk@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        // reward=1_000_000, desired=100 → 10_000 ISK/unit; contributor delivers 40 units → 400_000 ISK
        $projects = [array_merge(
            $this->makeProject('p1', current: 40, desired: 100, state: 'Closed'),
            ['reward' => ['initial' => 1_000_000, 'remaining' => 600_000]]
        )];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        $this->esiMock->shouldReceive('getCorporationProjectContributors')->once()
            ->andReturn([['character_id' => 111, 'name' => 'Alice', 'contributed' => 40]]);

        $result = $this->service->buildContributorLeaderboard($user->id);

        $this->assertCount(1, $result['contributors']);
        $this->assertEquals(400_000.0, $result['contributors'][0]['attributed_isk']);
    }

    public function test_leaderboard_aggregates_contributor_across_multiple_projects(): void
    {
        $user = $this->makeUser('lb-agg@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        // Both projects: 100 units desired, 1_000_000 initial → 10_000/unit
        $projects = [
            array_merge($this->makeProject('p1', current: 50, desired: 100, state: 'Closed'),
                ['reward' => ['initial' => 1_000_000, 'remaining' => 500_000]]),
            array_merge($this->makeProject('p2', current: 30, desired: 100, state: 'Completed'),
                ['reward' => ['initial' => 1_000_000, 'remaining' => 700_000]]),
        ];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        // Same character contributes to both projects
        $this->esiMock->shouldReceive('getCorporationProjectContributors')
            ->with(Mockery::any(), Mockery::any(), 'p1')->once()
            ->andReturn([['character_id' => 111, 'name' => 'Alice', 'contributed' => 50]]);
        $this->esiMock->shouldReceive('getCorporationProjectContributors')
            ->with(Mockery::any(), Mockery::any(), 'p2')->once()
            ->andReturn([['character_id' => 111, 'name' => 'Alice', 'contributed' => 30]]);

        $result = $this->service->buildContributorLeaderboard($user->id);

        $this->assertCount(1, $result['contributors']);
        $this->assertEquals(2, $result['contributors'][0]['project_count']);
        $this->assertEquals(800_000.0, $result['contributors'][0]['attributed_isk']);
    }

    public function test_leaderboard_computes_percentage_share(): void
    {
        $user = $this->makeUser('lb-pct@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [array_merge(
            $this->makeProject('p1', current: 100, desired: 100, state: 'Completed'),
            ['reward' => ['initial' => 1_000_000, 'remaining' => 0]]
        )];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        $this->esiMock->shouldReceive('getCorporationProjectContributors')->once()
            ->andReturn([
                ['character_id' => 111, 'name' => 'Alice', 'contributed' => 75],
                ['character_id' => 222, 'name' => 'Bob',   'contributed' => 25],
            ]);

        $result = $this->service->buildContributorLeaderboard($user->id);

        // Alice → 750_000 / 1_000_000 = 75%, Bob → 250_000 / 1_000_000 = 25%
        $this->assertEquals(75.0, $result['contributors'][0]['attributed_pct']);
        $this->assertEquals(25.0, $result['contributors'][1]['attributed_pct']);
    }

    public function test_leaderboard_sorts_descending_by_attributed_isk(): void
    {
        $user = $this->makeUser('lb-sort@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [array_merge(
            $this->makeProject('p1', current: 100, desired: 100, state: 'Closed'),
            ['reward' => ['initial' => 1_000_000, 'remaining' => 0]]
        )];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        $this->esiMock->shouldReceive('getCorporationProjectContributors')->once()
            ->andReturn([
                ['character_id' => 222, 'name' => 'Bob',   'contributed' => 10],
                ['character_id' => 111, 'name' => 'Alice', 'contributed' => 90],
            ]);

        $result = $this->service->buildContributorLeaderboard($user->id);

        $this->assertEquals('Alice', $result['contributors'][0]['character_name']);
        $this->assertEquals('Bob', $result['contributors'][1]['character_name']);
    }

    public function test_leaderboard_uses_esi_name_not_local_db(): void
    {
        $user = $this->makeUser('lb-name@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [array_merge(
            $this->makeProject('p1', current: 50, desired: 100, state: 'Closed'),
            ['reward' => ['initial' => 1_000_000, 'remaining' => 500_000]]
        )];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        $this->esiMock->shouldReceive('getCorporationProjectContributors')->once()
            ->andReturn([['character_id' => 99999, 'name' => 'ESI Name', 'contributed' => 50]]);

        $result = $this->service->buildContributorLeaderboard($user->id);

        $this->assertEquals('ESI Name', $result['contributors'][0]['character_name']);
    }

    public function test_leaderboard_filters_by_date_range(): void
    {
        $user = $this->makeUser('lb-date@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [
            array_merge(
                $this->makeProject('p1', current: 100, desired: 100, lastModified: '2026-01-10T00:00:00Z', state: 'Closed'),
                ['reward' => ['initial' => 1_000_000, 'remaining' => 0]]
            ),
            array_merge(
                $this->makeProject('p2', current: 100, desired: 100, lastModified: '2026-02-15T00:00:00Z', state: 'Closed'),
                ['reward' => ['initial' => 2_000_000, 'remaining' => 0]]
            ),
        ];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        // Only p2 is in range; p1 is excluded — so contributors mock is called once
        $this->esiMock->shouldReceive('getCorporationProjectContributors')
            ->with(Mockery::any(), Mockery::any(), 'p2')->once()
            ->andReturn([['character_id' => 111, 'name' => 'Alice', 'contributed' => 100]]);

        $result = $this->service->buildContributorLeaderboard($user->id, '2026-02-01', '2026-02-28');

        $this->assertEquals(1, $result['total_rewarded_projects']);
        $this->assertEquals(2_000_000.0, $result['total_attributed_isk']);
    }

    public function test_leaderboard_filters_by_project_name(): void
    {
        $user = $this->makeUser('lb-projname@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [
            array_merge(
                $this->makeProject('p1', current: 100, desired: 100, state: 'Closed'),
                ['name' => 'Alpha Mining Op', 'reward' => ['initial' => 1_000_000, 'remaining' => 0]]
            ),
            array_merge(
                $this->makeProject('p2', current: 100, desired: 100, state: 'Closed'),
                ['name' => 'Beta Hauling Run', 'reward' => ['initial' => 2_000_000, 'remaining' => 0]]
            ),
        ];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        // Only p1 matches "Mining"; p2 is excluded
        $this->esiMock->shouldReceive('getCorporationProjectContributors')
            ->with(Mockery::any(), Mockery::any(), 'p1')->once()
            ->andReturn([['character_id' => 111, 'name' => 'Alice', 'contributed' => 100]]);

        $result = $this->service->buildContributorLeaderboard($user->id, null, null, 'Mining');

        $this->assertEquals(1, $result['total_rewarded_projects']);
        $this->assertEquals(1_000_000.0, $result['total_attributed_isk']);
        $this->assertEquals('Mining', $result['project_name']);
    }

    public function test_leaderboard_project_name_filter_is_case_insensitive(): void
    {
        $user = $this->makeUser('lb-projcase@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [
            array_merge(
                $this->makeProject('p1', current: 100, desired: 100, state: 'Completed'),
                ['name' => 'Alpha Mining Op', 'reward' => ['initial' => 1_000_000, 'remaining' => 0]]
            ),
        ];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);
        $this->esiMock->shouldReceive('getCorporationProjectContributors')
            ->with(Mockery::any(), Mockery::any(), 'p1')->once()
            ->andReturn([['character_id' => 111, 'name' => 'Alice', 'contributed' => 100]]);

        $result = $this->service->buildContributorLeaderboard($user->id, null, null, 'mining');

        $this->assertEquals(1, $result['total_rewarded_projects']);
    }

    public function test_leaderboard_project_name_filter_no_match(): void
    {
        $user = $this->makeUser('lb-projnomatch@example.com');
        $this->makeCharacterForUser($user->id, withScope: true);

        $projects = [
            array_merge(
                $this->makeProject('p1', current: 100, desired: 100, state: 'Closed'),
                ['name' => 'Alpha Mining Op', 'reward' => ['initial' => 1_000_000, 'remaining' => 0]]
            ),
        ];

        $this->esiMock->shouldReceive('getCorporationProjects')->once()->andReturn($projects);

        $result = $this->service->buildContributorLeaderboard($user->id, null, null, 'Nonexistent');

        $this->assertEquals(0, $result['total_rewarded_projects']);
        $this->assertEmpty($result['contributors']);
    }

    public function test_leaderboard_returns_empty_when_no_access(): void
    {
        $user = $this->makeUser('lb-noaccess@example.com');
        $this->makeCharacterForUser($user->id, withScope: false);

        $result = $this->service->buildContributorLeaderboard($user->id);

        $this->assertEmpty($result['contributors']);
        $this->assertEquals(0.0, $result['total_attributed_isk']);
        $this->assertNull($result['corporation_name']);
    }

    // ---------------------------------------------------------------------------
    // ---------------------------------------------------------------------------

    private function makeUser(string $email = 'user@example.com'): User
    {
        return User::create([
            'name' => 'Test User',
            'email' => $email,
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    private function makeCharacter(): Character
    {
        $user = $this->makeUser('char-'.uniqid().'@example.com');

        return $this->makeCharacterForUser($user->id);
    }

    private function makeCharacterForUser(
        int $userId,
        bool $withScope = true,
        int $characterId = 12345,
        int $corpId = 1001,
        bool $isMain = true
    ): Character {
        return Character::create([
            'character_id' => $characterId,
            'user_id' => $userId,
            'name' => 'Test Char',
            'corporation_id' => $corpId,
            'corporation_name' => 'Test Corp',
            'is_main' => $isMain,
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => $withScope ? ['esi-corporations.read_projects.v1'] : ['esi-wallet.read_corporation_wallets.v1'],
        ]);
    }

    private function makeProject(
        string $id,
        int $current = 0,
        int $desired = 100,
        ?string $lastModified = null,
        string $state = 'Active'
    ): array {
        return [
            'id' => $id,
            'name' => "Project {$id}",
            'state' => $state,
            'last_modified' => $lastModified ?? Carbon::now()->toIso8601String(),
            'progress' => ['current' => $current, 'desired' => $desired],
        ];
    }
}
