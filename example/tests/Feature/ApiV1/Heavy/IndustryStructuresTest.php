<?php

namespace Tests\Feature\ApiV1\Heavy;

use App\Models\StationConfig;
use App\Services\IndustryService;
use App\Services\ReprocessingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Heavy\HeavyFixtures;
use Tests\TestCase;

class IndustryStructuresTest extends TestCase
{
    use HeavyFixtures, RefreshDatabase;

    private function slots(): array
    {
        $job = [
            'job_id' => 7, 'activity_id' => 1, 'activity_name' => 'Manufacturing', 'blueprint_type_id' => 688,
            'product_type_id' => 587, 'item_name' => 'Rifter', 'item_image_url' => 'http://i', 'runs' => 10,
            'licensed_runs' => 10, 'start_date' => '2026-10-21T10:00:00+00:00', 'end_date' => '2026-10-21T14:00:00+00:00',
            'progress_pct' => 50, 'time_remaining' => '2h 0m', 'seconds_remaining' => 7200, 'ready_for_delivery' => false,
            'status' => 'active', 'probability_pct' => null, 'probability_label' => null,
        ];
        $kind = ['total' => 3, 'active' => 1, 'available' => 2, 'jobs' => [$job]];
        $empty = ['total' => 1, 'active' => 0, 'available' => 1, 'jobs' => []];
        $skills = ['mass_production' => 4, 'adv_mass_production' => 0, 'laboratory_operation' => 0,
            'adv_laboratory_operation' => 0, 'mass_reactions' => 0, 'adv_mass_reactions' => 0];

        return [
            'characters' => [[
                'character_id' => 1, 'character_name' => 'A', 'portrait_url' => 'http://p', 'corporation_name' => 'C',
                'manufacturing' => $kind, 'research' => $empty, 'reactions' => $empty,
                'completing_soon' => [], 'skills' => $skills,
            ]],
            'totals' => [
                'manufacturing' => ['total' => 3, 'active' => 1, 'available' => 2],
                'research' => ['total' => 1, 'active' => 0, 'available' => 1],
                'reactions' => ['total' => 1, 'active' => 0, 'available' => 1],
            ],
        ];
    }

    private function config(int $userId, string $name, string $activity, bool $default = false): StationConfig
    {
        return StationConfig::create([
            'user_id' => $userId, 'activity_type' => $activity, 'name' => $name, 'structure_type' => 'raitaru',
            'rig_me' => 't1', 'rig_te' => 'none', 'system_security' => 'lowsec', 'tax_rate' => 1.5, 'is_default' => $default,
        ]);
    }

    public function test_requires_auth(): void
    {
        foreach (['/industry/availability', '/industry/reprocessing', '/structures'] as $path) {
            $this->getJson('/api/v1'.$path)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_availability_envelope(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
        $user = $this->makeUser();
        $ready = [[
            'character_id' => 1, 'character_name' => 'A', 'job_id' => 9, 'activity_id' => 8, 'activity_name' => 'Invention',
            'item_name' => 'Hound', 'item_image_url' => null, 'end_date' => '2026-10-21T12:00:30+00:00',
            'location' => 'Jita IV - Moon 4', 'runs' => 1, 'probability_pct' => 41.5, 'probability_label' => '41% Chance',
        ]];
        $this->mock(IndustryService::class, function ($mock) use ($user, $ready) {
            $mock->shouldReceive('getAvailabilityForUser')->once()->with($user->id)->andReturn($this->slots());
            $mock->shouldReceive('getReadyForCollectionJobsForUser')->once()->with($user->id, 60)->andReturn($ready);
        });

        $this->actingAs($user)->getJson('/api/v1/industry/availability')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'characters' => [['character_id', 'character_name', 'portrait_url', 'corporation_name', 'manufacturing' => ['total', 'active', 'available', 'jobs'], 'research', 'reactions', 'completing_soon', 'skills']],
                'totals' => ['manufacturing', 'research', 'reactions'],
                'ready_for_collection',
            ], 'meta' => ['cached_until']])
            ->assertJsonPath('meta.cached_until', '2026-10-21T12:20:00+00:00')
            ->assertJsonPath('data.characters.0.manufacturing.available', 2)
            ->assertJsonPath('data.characters.0.manufacturing.jobs.0.item_name', 'Rifter')
            ->assertJsonPath('data.characters.0.manufacturing.jobs.0.progress_pct', 50)
            ->assertJsonPath('data.characters.0.skills.mass_production', 4)
            ->assertJsonPath('data.totals.manufacturing.total', 3)
            ->assertJsonPath('data.ready_for_collection.0.job_id', 9)
            ->assertJsonPath('data.ready_for_collection.0.location', 'Jita IV - Moon 4')
            ->assertJsonPath('data.ready_for_collection.0.probability_pct', 41.5);
    }

    public function test_reprocessing_envelope_and_freshness(): void
    {
        $user = $this->makeUser();
        $item = fn (string $name, float $profit) => [
            'type_id' => 1, 'name' => $name, 'quantity' => 10, 'market_value' => 100, 'reprocess_value' => 100 + $profit,
            'profit' => $profit, 'profit_pct' => $profit, 'unit_market_price' => 10, 'icon_url' => 'http://i',
            'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 500, 'unit_price' => 0.4, 'total_value' => 200]],
        ];
        $location = fn (string $name, array $items) => [
            'location_id' => 60003760, 'location_name' => $name, 'items' => $items,
            'total_market_value' => 100 * count($items),
            'total_reprocess_value' => 100 * count($items) + array_sum(array_column($items, 'profit')),
            'total_profit' => array_sum(array_column($items, 'profit')),
        ];
        $characters = [[
            'character_id' => 1, 'character_name' => 'A', 'portrait_url' => null, 'corporation_name' => 'C', 'yield_pct' => 54.3,
            'skills' => ['reprocessing' => 5, 'reprocessing_efficiency' => 4, 'scrapmetal_processing' => 0],
            'locations' => [$location('Jita', [$item('X', 60), $item('Y', 40)]), $location('Amarr', [$item('Z', 20)])],
            'total_profit' => 120,
        ]];
        $this->mock(ReprocessingService::class)->shouldReceive('getReprocessingOpportunities')->once()->with($user->id)->andReturn($characters);

        $this->actingAs($user)->getJson('/api/v1/industry/reprocessing')
            ->assertOk()
            ->assertJsonPath('data.characters.0.total_profit', 120)
            ->assertJsonPath('data.characters.0.yield_pct', 54.3)
            ->assertJsonPath('data.characters.0.skills.reprocessing_efficiency', 4)
            ->assertJsonPath('data.characters.0.locations.0.location_name', 'Jita')
            ->assertJsonPath('data.characters.0.locations.0.items.0.materials.0.name', 'Tritanium')
            ->assertJsonPath('data.totals', ['profit' => 120, 'characters' => 1, 'items' => 3])
            ->assertJsonStructure(['meta' => ['freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale']]])
            // No market data in the test database.
            ->assertJsonPath('meta.freshness.source', 'none')
            ->assertJsonPath('meta.freshness.stale', true);
    }

    public function test_structures_are_own_only_sorted_and_paginated(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $this->config($user->id, 'Zed', 'manufacturing');
        $this->config($user->id, 'Alpha', 'reaction');
        $this->config($user->id, 'Mid', 'manufacturing', true);
        $this->config($other->id, 'Secret', 'manufacturing');

        $response = $this->actingAs($user)->getJson('/api/v1/structures')->assertOk();
        $response->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 50, 'total' => 3, 'last_page' => 1])
            ->assertJsonPath('data.*.name', ['Mid', 'Alpha', 'Zed'])
            ->assertJsonPath('data.0.tax_rate', 1.5)
            ->assertJsonPath('data.0.is_default', true);
        $this->assertSame(
            ['id', 'activity_type', 'name', 'structure_type', 'rig_me', 'rig_te', 'system_security', 'solar_system_id', 'solar_system_name', 'tax_rate', 'is_default', 'effective_bonuses'],
            array_keys($response->json('data.0')),
        );

        $this->actingAs($user)->getJson('/api/v1/structures?per_page=2&page=2')
            ->assertOk()->assertJsonPath('data.*.name', ['Zed'])->assertJsonPath('meta.pagination.last_page', 2);
    }

    public function test_structures_carry_the_solar_system_name(): void
    {
        $user = $this->makeUser();
        \App\Models\SdeSolarSystem::create([
            'solar_system_id' => 30000142, 'solar_system_name' => 'Jita', 'constellation_id' => 1, 'region_id' => 10000002, 'security' => 0.9,
        ]);
        $this->config($user->id, 'Here', 'manufacturing')->update(['solar_system_id' => 30000142]);
        $this->config($user->id, 'Nowhere', 'reaction');

        $this->actingAs($user)->getJson('/api/v1/structures')->assertOk()
            ->assertJsonPath('data.0.solar_system_name', 'Jita')
            ->assertJsonPath('data.0.solar_system_id', 30000142)
            ->assertJsonPath('data.1.solar_system_name', null);
    }

    public function test_structures_activity_filter_and_validation(): void
    {
        $user = $this->makeUser();
        $this->config($user->id, 'M', 'manufacturing');
        $this->config($user->id, 'R', 'reaction');

        $this->actingAs($user)->getJson('/api/v1/structures?activity_type=reaction')
            ->assertOk()->assertJsonPath('data.*.name', ['R']);
        $this->actingAs($user)->getJson('/api/v1/structures?activity_type=bogus')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('activity_type');
    }

    public function test_structures_carry_the_bonuses_the_calculators_apply(): void
    {
        $user = $this->makeUser();
        // Raitaru role bonus and a T1 rig in lowsec (x1.9) combine multiplicatively.
        $this->config($user->id, 'M', 'manufacturing');
        // Reactions get the rig bonus only (lowsec multiplier 1.0) and no time bonus.
        $this->config($user->id, 'R', 'reaction');

        $response = $this->actingAs($user)->getJson('/api/v1/structures')->assertOk();
        $byName = collect($response->json('data'))->keyBy('name');

        $this->assertEqualsWithDelta((1 - 0.99 * (1 - 0.038)) * 100, $byName['M']['effective_bonuses']['me'], 0.0001);
        $this->assertEqualsWithDelta(3.0, $byName['M']['effective_bonuses']['te'], 0.0001);
        $this->assertEqualsWithDelta(2.0, $byName['R']['effective_bonuses']['me'], 0.0001);
        $this->assertEqualsWithDelta(0.0, $byName['R']['effective_bonuses']['te'], 0.0001);
    }
}
