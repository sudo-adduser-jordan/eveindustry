<?php

namespace Tests\Unit\Esi;

use App\Models\Character;
use App\Services\Esi\CorporationClient;
use App\Services\Esi\EsiAuthClient;
use App\Services\Esi\EsiHttpClient;
use App\Services\Esi\EsiResponse;
use App\Services\Esi\IndustryClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class CorporationCompletenessTest extends TestCase
{
    private function character(array $overrides = []): Character
    {
        return new Character(array_merge([
            'character_id' => 42, 'user_id' => 1, 'corporation_id' => 98,
            'is_esi_active' => true, 'roles' => ['Factory_Manager', 'Project_Manager', 'Director'],
            'scopes' => ['esi-industry.read_corporation_jobs.v1', 'esi-corporations.read_projects.v1', 'esi-assets.read_corporation_assets.v1'],
        ], $overrides));
    }

    private function response(array $data): EsiResponse
    {
        return new EsiResponse(new Response(new PsrResponse(200, [], json_encode($data))));
    }

    public function test_corporation_industry_uses_all_pages_and_requires_scope_and_factory_role(): void
    {
        $http = Mockery::mock(EsiHttpClient::class);
        $http->shouldReceive('authenticatedPaginatedRequest')->once()
            ->with('/corporations/98/industry/jobs/?include_completed=true', Mockery::type(Character::class))
            ->andReturn([['job_id' => 1], ['job_id' => 2]]);
        $client = new IndustryClient($http);
        $this->assertNull($client->getCorporationIndustryJobs($this->character(['scopes' => []]), 98, true));
        $this->assertNull($client->getCorporationIndustryJobs($this->character(['roles' => ['Director']]), 98, true));
        $this->assertCount(2, $client->getCorporationIndustryJobs($this->character(), 98, true));
    }

    public function test_cursor_projects_and_more_than_100_contributors_continue_after_short_pages(): void
    {
        $http = Mockery::mock(EsiHttpClient::class);
        $http->shouldReceive('authenticatedResponse')->once()->with(
            'get', '/corporations/98/projects', Mockery::type(Character::class), false,
            ['state' => 'All', 'after' => '0', 'limit' => 100], []
        )->andReturn($this->response(['projects' => [['id' => 'a']], 'cursor' => ['after' => 'opaque']]));
        $http->shouldReceive('authenticatedResponse')->once()->with(
            'get', '/corporations/98/projects', Mockery::type(Character::class), false,
            ['state' => 'All', 'after' => 'opaque', 'limit' => 100], []
        )->andReturn($this->response(['projects' => [['id' => 'b']]]));
        $http->shouldReceive('authenticatedResponse')->once()->with(
            'get', '/corporations/98/projects/p/contributors', Mockery::type(Character::class), false,
            ['after' => '0', 'limit' => 100], []
        )->andReturn($this->response(['contributors' => array_map(fn ($id) => ['character_id' => $id], range(1, 100)), 'cursor' => ['after' => 'next']]));
        $http->shouldReceive('authenticatedResponse')->once()->with(
            'get', '/corporations/98/projects/p/contributors', Mockery::type(Character::class), false,
            ['after' => 'next', 'limit' => 100], []
        )->andReturn($this->response(['contributors' => [['character_id' => 100], ['character_id' => 101]]]));
        $client = new CorporationClient($http, Mockery::mock(EsiAuthClient::class));
        $this->assertCount(2, $client->getCorporationProjects($this->character(), 98));
        $this->assertCount(101, $client->getCorporationProjectContributors($this->character(), 98, 'p'));
    }

    public function test_repeated_cursor_never_caches_a_partial_result(): void
    {
        $http = Mockery::mock(EsiHttpClient::class);
        $http->shouldReceive('authenticatedResponse')->twice()->andReturn(
            $this->response(['projects' => [['id' => 'a']], 'cursor' => ['after' => 'loop']])
        );
        $client = new CorporationClient($http, Mockery::mock(EsiAuthClient::class));
        try {
            $client->getCorporationProjects($this->character(), 98);
            $this->fail('Repeated cursors must fail collection');
        } catch (\RuntimeException $e) {
            $this->assertFalse(Cache::has('esi.corp_projects.98'));
        }
    }

    public function test_failed_later_cursor_page_does_not_cache_partial_contributors(): void
    {
        $http = Mockery::mock(EsiHttpClient::class);
        $http->shouldReceive('authenticatedResponse')->once()->ordered()
            ->andReturn($this->response(['contributors' => [['character_id' => 1]], 'cursor' => ['after' => 'next']]));
        $http->shouldReceive('authenticatedResponse')->once()->ordered()->andThrow(new \RuntimeException('upstream unavailable'));
        $client = new CorporationClient($http, Mockery::mock(EsiAuthClient::class));
        try {
            $client->getCorporationProjectContributors($this->character(), 98, 'p');
            $this->fail('A failed page must abort');
        } catch (\RuntimeException $e) {
            $this->assertSame('upstream unavailable', $e->getMessage());
            $this->assertFalse(Cache::has('esi.corp_project_contributors.98.p'));
        }
    }

    public function test_ineligible_character_cannot_read_shared_corporation_cache(): void
    {
        Cache::put('esi.corp_assets.98', [['item_id' => 5]], 300);
        $http = Mockery::mock(EsiHttpClient::class);
        $http->shouldNotReceive('authenticatedPaginatedRequest');
        $client = new CorporationClient($http, Mockery::mock(EsiAuthClient::class));
        $this->assertSame([], $client->getCorporationAssets($this->character(['corporation_id' => 99]), 98));
        $this->assertSame([], $client->getCorporationAssets($this->character(['roles' => []]), 98));
    }

    public function test_complete_empty_corporation_assets_are_cached(): void
    {
        $http = Mockery::mock(EsiHttpClient::class);
        $http->shouldReceive('authenticatedPaginatedRequest')->once()->andReturn([]);
        $client = new CorporationClient($http, Mockery::mock(EsiAuthClient::class));
        $this->assertSame([], $client->getCorporationAssets($this->character(), 98));
        $this->assertSame([], $client->getCorporationAssets($this->character(), 98));
        $this->assertTrue(Cache::has('esi.corp_assets.98'));
    }

    public function test_forbidden_collector_is_suppressed_without_caching_a_false_empty_inventory(): void
    {
        $http = Mockery::mock(EsiHttpClient::class);
        $http->shouldReceive('authenticatedPaginatedRequest')->once()
            ->andThrow(new \App\Services\Esi\EsiRequestException('forbidden'));
        $client = new CorporationClient($http, Mockery::mock(EsiAuthClient::class));
        try {
            $client->getCorporationAssets($this->character(), 98);
            $this->fail('A forbidden request must abort collection');
        } catch (\App\Services\Esi\EsiRequestException $e) {
            $this->assertFalse(Cache::has('esi.corp_assets.98'));
        }
        $this->assertSame([], $client->getCorporationAssets($this->character(), 98));
    }
}
