<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\EsiService;
use App\Services\IndustryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class IndustryServiceTest extends TestCase
{
    use RefreshDatabase;

    private IndustryService $service;

    private $esiMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esiMock = Mockery::mock(EsiService::class);
        $this->service = new IndustryService($this->esiMock);
    }

    private function makeCharacter(array $overrides = []): Character
    {
        return new Character(array_merge([
            'character_id' => 12345,
            'user_id' => 1,
            'name' => 'Test Char',
            'corporation_id' => 98000,
            'corporation_name' => 'Test Corp',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'is_esi_active' => true,
            'roles' => ['Factory_Manager'],
            'scopes' => [
                'esi-industry.read_character_jobs.v1',
                'esi-industry.read_corporation_jobs.v1',
                'esi-skills.read_skills.v1',
            ],
        ], $overrides));
    }

    public function test_get_active_jobs_merges_corp_jobs_for_character(): void
    {
        $character = $this->makeCharacter();

        $personalJobs = [
            ['job_id' => 1, 'activity_id' => 1, 'status' => 'active', 'installer_id' => 12345],
        ];

        $corpJobs = [
            ['job_id' => 2, 'activity_id' => 1, 'status' => 'active', 'installer_id' => 12345],
            ['job_id' => 3, 'activity_id' => 1, 'status' => 'active', 'installer_id' => 99999], // different character
            ['job_id' => 4, 'activity_id' => 1, 'status' => 'delivered', 'installer_id' => 12345], // not active
        ];

        $this->esiMock->shouldReceive('getCharacterIndustryJobs')
            ->once()
            ->with($character)
            ->andReturn($personalJobs);

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')
            ->once()
            ->with($character, 98000, false)
            ->andReturn($corpJobs);

        $method = new \ReflectionMethod(IndustryService::class, 'getActiveJobs');
        $result = $method->invoke($this->service, $character);

        // Should include personal job 1 and corp job 2 (active, same installer)
        // Should exclude corp job 3 (different installer) and corp job 4 (not active)
        $jobIds = array_column($result, 'job_id');
        sort($jobIds);
        $this->assertEquals([1, 2], $jobIds);
    }

    public function test_get_active_jobs_skips_corp_without_scope(): void
    {
        $character = $this->makeCharacter([
            'scopes' => ['esi-industry.read_character_jobs.v1'],
        ]);

        $personalJobs = [
            ['job_id' => 1, 'activity_id' => 1, 'status' => 'active', 'installer_id' => 12345],
        ];

        $this->esiMock->shouldReceive('getCharacterIndustryJobs')
            ->once()
            ->with($character)
            ->andReturn($personalJobs);

        // Should NOT call getCorporationIndustryJobs since scope is missing
        $this->esiMock->shouldNotReceive('getCorporationIndustryJobs');

        $method = new \ReflectionMethod(IndustryService::class, 'getActiveJobs');
        $result = $method->invoke($this->service, $character);

        $this->assertCount(1, $result);
        $this->assertEquals(1, array_values($result)[0]['job_id']);
    }

    public function test_get_corporation_jobs_for_character_filters_by_installer(): void
    {
        $character = $this->makeCharacter();

        $corpJobs = [
            ['job_id' => 10, 'activity_id' => 1, 'status' => 'active', 'installer_id' => 12345],
            ['job_id' => 11, 'activity_id' => 1, 'status' => 'active', 'installer_id' => 67890],
            ['job_id' => 12, 'activity_id' => 9, 'status' => 'active', 'installer_id' => 12345],
        ];

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')
            ->once()
            ->with($character, 98000, false)
            ->andReturn($corpJobs);

        $method = new \ReflectionMethod(IndustryService::class, 'getCorporationJobsForCharacter');
        $result = $method->invoke($this->service, $character, false);

        $jobIds = array_column($result, 'job_id');
        sort($jobIds);
        $this->assertEquals([10, 12], $jobIds);
    }

    public function test_get_corporation_jobs_returns_empty_when_no_corporation_id(): void
    {
        $character = $this->makeCharacter(['corporation_id' => 0]);

        $this->esiMock->shouldNotReceive('getCorporationIndustryJobs');

        $method = new \ReflectionMethod(IndustryService::class, 'getCorporationJobsForCharacter');
        $result = $method->invoke($this->service, $character, false);

        $this->assertEmpty($result);
    }

    public function test_get_corporation_jobs_throws_on_failed_response(): void
    {
        $character = $this->makeCharacter();

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')
            ->once()
            ->andReturn(null);

        $method = new \ReflectionMethod(IndustryService::class, 'getCorporationJobsForCharacter');
        $this->expectException(\RuntimeException::class);
        $method->invoke($this->service, $character, false);
    }

    public function test_jobs_overview_manufacturing_expected_output_uses_product_quantity(): void
    {
        $user = \App\Models\User::create(['name' => 'Test User', 'email' => 'mfg@example.com', 'password' => bcrypt('password'), 'is_active' => true]);
        $character = $this->makeCharacter(['user_id' => $user->id]);
        $character->save();

        $now = now();
        $start = $now->copy()->subHour();
        $end = $now->copy()->addHour();

        $this->esiMock->shouldReceive('getCharacterIndustryJobs')
            ->andReturn([
                [
                    'job_id' => 100,
                    'activity_id' => IndustryService::ACTIVITY_MANUFACTURING,
                    'status' => 'active',
                    'installer_id' => 12345,
                    'blueprint_type_id' => 1000,
                    'product_type_id' => 2000,
                    'runs' => 5,
                    'start_date' => $start->toIso8601String(),
                    'end_date' => $end->toIso8601String(),
                ],
            ]);

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')
            ->andReturn([]);

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(2000)
            ->andReturn(['name' => 'Scourge Fury Heavy Missile']);

        $this->esiMock->shouldReceive('getBestTypeImageUrl')
            ->andReturn(null);

        $this->esiMock->shouldReceive('resolveBlueprintActivity')
            ->with(1000, IndustryService::ACTIVITY_MANUFACTURING)
            ->andReturn([
                'materials' => [],
                'products' => [['type_id' => 2000, 'name' => 'Scourge Fury Heavy Missile', 'quantity' => 100]],
                'time' => 3600,
            ]);

        $result = $this->service->getJobsOverviewForUser($user->id);

        $manufacturingJobs = $result['Manufacturing'] ?? [];
        $this->assertCount(1, $manufacturingJobs);
        $this->assertEquals(500, $manufacturingJobs[0]['expected_output']);
    }

    public function test_jobs_overview_manufacturing_falls_back_to_portion_size(): void
    {
        $user = \App\Models\User::create(['name' => 'Test User', 'email' => 'mfg-fallback@example.com', 'password' => bcrypt('password'), 'is_active' => true]);
        $character = $this->makeCharacter(['user_id' => $user->id]);
        $character->save();

        $now = now();
        $start = $now->copy()->subHour();
        $end = $now->copy()->addHour();

        $this->esiMock->shouldReceive('getCharacterIndustryJobs')
            ->andReturn([
                [
                    'job_id' => 101,
                    'activity_id' => IndustryService::ACTIVITY_MANUFACTURING,
                    'status' => 'active',
                    'installer_id' => 12345,
                    'blueprint_type_id' => 1000,
                    'product_type_id' => 2000,
                    'runs' => 5,
                    'start_date' => $start->toIso8601String(),
                    'end_date' => $end->toIso8601String(),
                ],
            ]);

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')
            ->andReturn([]);

        // ESI type info includes portion_size
        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(2000)
            ->andReturn(['name' => 'Scourge Fury Heavy Missile', 'portion_size' => 100]);

        $this->esiMock->shouldReceive('getBestTypeImageUrl')
            ->andReturn(null);

        // SDE lookup fails — returns null
        $this->esiMock->shouldReceive('resolveBlueprintActivity')
            ->with(1000, IndustryService::ACTIVITY_MANUFACTURING)
            ->andReturn(null);

        $result = $this->service->getJobsOverviewForUser($user->id);

        $manufacturingJobs = $result['Manufacturing'] ?? [];
        $this->assertCount(1, $manufacturingJobs);
        // Should fall back to portion_size (100) × runs (5) = 500
        $this->assertEquals(500, $manufacturingJobs[0]['expected_output']);
    }

    public function test_jobs_overview_reactions_expected_output_uses_product_quantity(): void
    {
        $user = \App\Models\User::create(['name' => 'Test User', 'email' => 'react@example.com', 'password' => bcrypt('password'), 'is_active' => true]);
        $character = $this->makeCharacter(['user_id' => $user->id]);
        $character->save();

        $now = now();
        $start = $now->copy()->subHour();
        $end = $now->copy()->addHour();

        $this->esiMock->shouldReceive('getCharacterIndustryJobs')
            ->andReturn([
                [
                    'job_id' => 200,
                    'activity_id' => IndustryService::ACTIVITY_REACTIONS,
                    'status' => 'active',
                    'installer_id' => 12345,
                    'blueprint_type_id' => 3000,
                    'product_type_id' => 4000,
                    'runs' => 10,
                    'start_date' => $start->toIso8601String(),
                    'end_date' => $end->toIso8601String(),
                ],
            ]);

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')
            ->andReturn([]);

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(4000)
            ->andReturn(['name' => 'Fullerite-C320']);

        $this->esiMock->shouldReceive('getBestTypeImageUrl')
            ->andReturn(null);

        // Reactions use SDE activity ID 11
        $this->esiMock->shouldReceive('resolveBlueprintActivity')
            ->with(3000, 11)
            ->andReturn([
                'materials' => [],
                'products' => [['type_id' => 4000, 'name' => 'Fullerite-C320', 'quantity' => 200]],
                'time' => 7200,
            ]);

        $result = $this->service->getJobsOverviewForUser($user->id);

        $reactionJobs = $result['Reactions'] ?? [];
        $this->assertCount(1, $reactionJobs);
        $this->assertEquals(2000, $reactionJobs[0]['expected_output']);
    }

    public function test_jobs_overview_research_expected_output_equals_runs(): void
    {
        $user = \App\Models\User::create(['name' => 'Test User', 'email' => 'research@example.com', 'password' => bcrypt('password'), 'is_active' => true]);
        $character = $this->makeCharacter(['user_id' => $user->id]);
        $character->save();

        $now = now();
        $start = $now->copy()->subHour();
        $end = $now->copy()->addHour();

        $this->esiMock->shouldReceive('getCharacterIndustryJobs')
            ->andReturn([
                [
                    'job_id' => 300,
                    'activity_id' => IndustryService::ACTIVITY_RESEARCH_ME,
                    'status' => 'active',
                    'installer_id' => 12345,
                    'blueprint_type_id' => 5000,
                    'product_type_id' => 5000,
                    'runs' => 3,
                    'start_date' => $start->toIso8601String(),
                    'end_date' => $end->toIso8601String(),
                ],
            ]);

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')
            ->andReturn([]);

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(5000)
            ->andReturn(['name' => 'Some Blueprint']);

        $this->esiMock->shouldReceive('getBestTypeImageUrl')
            ->andReturn(null);

        $result = $this->service->getJobsOverviewForUser($user->id);

        $researchJobs = $result['Material Efficiency Research'] ?? [];
        $this->assertCount(1, $researchJobs);
        $this->assertEquals(3, $researchJobs[0]['expected_output']);
    }

    public function test_jobs_overview_includes_npc_station_location(): void
    {
        $user = \App\Models\User::create(['name' => 'Test User', 'email' => 'loc-npc@example.com', 'password' => bcrypt('password'), 'is_active' => true]);
        $character = $this->makeCharacter(['user_id' => $user->id]);
        $character->save();

        $now = now();
        $start = $now->copy()->subHour();
        $end = $now->copy()->addHour();

        $this->esiMock->shouldReceive('getCharacterIndustryJobs')
            ->andReturn([
                [
                    'job_id' => 400,
                    'activity_id' => IndustryService::ACTIVITY_MANUFACTURING,
                    'status' => 'active',
                    'installer_id' => 12345,
                    'blueprint_type_id' => 1000,
                    'product_type_id' => 2000,
                    'runs' => 1,
                    'station_id' => 60003760,
                    'start_date' => $start->toIso8601String(),
                    'end_date' => $end->toIso8601String(),
                ],
            ]);

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')->andReturn([]);
        $this->esiMock->shouldReceive('getTypeInfo')->with(2000)->andReturn(['name' => 'Rifter']);
        $this->esiMock->shouldReceive('getBestTypeImageUrl')->andReturn(null);
        $this->esiMock->shouldReceive('resolveBlueprintActivity')->andReturn(null);
        $this->esiMock->shouldReceive('getStation')->with(60003760)->andReturn(['name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant']);

        $result = $this->service->getJobsOverviewForUser($user->id);

        $jobs = $result['Manufacturing'] ?? [];
        $this->assertCount(1, $jobs);
        $this->assertEquals('Jita IV - Moon 4 - Caldari Navy Assembly Plant', $jobs[0]['location']);
    }

    public function test_jobs_overview_includes_player_structure_location(): void
    {
        $user = \App\Models\User::create(['name' => 'Test User', 'email' => 'loc-struct@example.com', 'password' => bcrypt('password'), 'is_active' => true]);
        $character = $this->makeCharacter(['user_id' => $user->id]);
        $character->save();

        $now = now();
        $start = $now->copy()->subHour();
        $end = $now->copy()->addHour();

        $this->esiMock->shouldReceive('getCharacterIndustryJobs')
            ->andReturn([
                [
                    'job_id' => 401,
                    'activity_id' => IndustryService::ACTIVITY_REACTIONS,
                    'status' => 'active',
                    'installer_id' => 12345,
                    'blueprint_type_id' => 3000,
                    'product_type_id' => 4000,
                    'runs' => 5,
                    'station_id' => 1038457745274,
                    'start_date' => $start->toIso8601String(),
                    'end_date' => $end->toIso8601String(),
                ],
            ]);

        $this->esiMock->shouldReceive('getCorporationIndustryJobs')->andReturn([]);
        $this->esiMock->shouldReceive('getTypeInfo')->with(4000)->andReturn(['name' => 'Fullerite-C320']);
        $this->esiMock->shouldReceive('getBestTypeImageUrl')->andReturn(null);
        $this->esiMock->shouldReceive('resolveBlueprintActivity')->andReturn(null);

        // NPC station lookup fails for player structure IDs
        $this->esiMock->shouldReceive('getStation')->with(1038457745274)->andReturn(null);
        // Structure lookup succeeds
        $this->esiMock->shouldReceive('getStructure')
            ->with(1038457745274, Mockery::type(Character::class))
            ->andReturn(['name' => 'My Player Citadel']);

        $result = $this->service->getJobsOverviewForUser($user->id);

        $jobs = $result['Reactions'] ?? [];
        $this->assertCount(1, $jobs);
        $this->assertEquals('My Player Citadel', $jobs[0]['location']);
    }

    public function test_build_production_summary_aggregates_expected_output(): void
    {
        $groupedJobs = [
            'Manufacturing' => [
                [
                    'item_name' => 'Scourge Fury Heavy Missile',
                    'item_image_url' => null,
                    'expected_output' => 500,
                    'runs' => 5,
                ],
                [
                    'item_name' => 'Scourge Fury Heavy Missile',
                    'item_image_url' => null,
                    'expected_output' => 300,
                    'runs' => 3,
                ],
            ],
        ];

        $summary = $this->service->buildProductionSummary($groupedJobs);

        $this->assertCount(1, $summary);
        $this->assertEquals('Scourge Fury Heavy Missile', $summary[0]['item_name']);
        $this->assertEquals(800, $summary[0]['total_expected_output']);
        $this->assertEquals(8, $summary[0]['total_runs']);
        $this->assertEquals(2, $summary[0]['job_count']);
    }

    public function test_build_production_summary_excludes_non_value_activities(): void
    {
        $groupedJobs = [
            'Manufacturing' => [
                [
                    'item_name' => 'Rifter',
                    'item_image_url' => null,
                    'type_id' => 587,
                    'expected_output' => 100,
                    'runs' => 1,
                ],
            ],
            'Reactions' => [
                [
                    'item_name' => 'Fullerite-C320',
                    'item_image_url' => null,
                    'type_id' => 30370,
                    'expected_output' => 200,
                    'runs' => 10,
                ],
            ],
            'Invention' => [
                [
                    'item_name' => 'Rifter Blueprint',
                    'item_image_url' => null,
                    'type_id' => 588,
                    'expected_output' => 0.6,
                    'runs' => 1,
                ],
            ],
            'Time Efficiency Research' => [
                [
                    'item_name' => 'Rifter Blueprint',
                    'item_image_url' => null,
                    'type_id' => 588,
                    'expected_output' => 3,
                    'runs' => 3,
                ],
            ],
            'Material Efficiency Research' => [
                [
                    'item_name' => 'Rifter Blueprint',
                    'item_image_url' => null,
                    'type_id' => 588,
                    'expected_output' => 5,
                    'runs' => 5,
                ],
            ],
            'Copying' => [
                [
                    'item_name' => 'Rifter Blueprint Copy',
                    'item_image_url' => null,
                    'type_id' => 589,
                    'expected_output' => 2,
                    'runs' => 2,
                ],
            ],
        ];

        $summary = $this->service->buildProductionSummary($groupedJobs);

        // Only Manufacturing and Reactions should be included
        $this->assertCount(2, $summary);
        $itemNames = array_column($summary, 'item_name');
        $this->assertContains('Rifter', $itemNames);
        $this->assertContains('Fullerite-C320', $itemNames);
        $this->assertNotContains('Rifter Blueprint', $itemNames);
        $this->assertNotContains('Rifter Blueprint Copy', $itemNames);
    }

    public function test_get_corporation_jobs_skips_when_no_scope_and_no_factory_manager_role(): void
    {
        $character = $this->makeCharacter([
            'scopes' => ['esi-industry.read_character_jobs.v1'],
            'roles' => ['Accountant'],
        ]);

        $this->esiMock->shouldNotReceive('getCorporationIndustryJobs');

        $method = new \ReflectionMethod(IndustryService::class, 'getCorporationJobsForCharacter');
        $result = $method->invoke($this->service, $character, false);

        $this->assertEmpty($result);
    }

    public function test_get_corporation_jobs_skips_factory_manager_without_scope(): void
    {
        $character = $this->makeCharacter(['scopes' => ['esi-industry.read_character_jobs.v1']]);
        $this->esiMock->shouldNotReceive('getCorporationIndustryJobs');
        $method = new \ReflectionMethod(IndustryService::class, 'getCorporationJobsForCharacter');
        $this->assertEmpty($method->invoke($this->service, $character, false));
    }

    public function test_ready_for_collection_window_does_not_grow_per_job(): void
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-23 12:00:00', 'UTC'));
        $character = $this->makeCharacter();
        $job = fn (int $id, int $endsIn) => [
            'job_id' => $id, 'activity_id' => 1, 'runs' => 1,
            'end_date' => now()->addSeconds($endsIn)->toIso8601String(),
        ];

        $service = Mockery::mock(IndustryService::class, [$this->esiMock])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('charactersForUser')->andReturn(collect([$character]));
        // 30 s and 90 s out: only the first is inside a 60 s window, whatever the order.
        $service->shouldReceive('getActiveJobs')->andReturn([$job(1, 30), $job(2, 90), $job(3, 45)]);

        $ready = $service->getReadyForCollectionJobsForUser(1, 60);

        $this->assertSame([1, 3], array_column($ready, 'job_id'));
    }
}
