<?php

namespace Tests\Feature\ApiV1\Reference;

use App\Models\SdeStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Reference\SeedsReferenceData;
use Tests\TestCase;

class SystemsStationsAndCostIndicesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blockOutsideHttp();
        $this->seedSystems();
        $this->seedStations();
        $this->user = $this->makeUser();
    }

    public function test_system_search_is_substring_with_raw_security(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/systems?q=ita')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['solar_system_id' => 30045349, 'solar_system_name' => 'Itamo', 'constellation_id' => 20000020, 'region_id' => 10000016, 'security' => 0.6489],
                    ['solar_system_id' => 30000142, 'solar_system_name' => 'Jita', 'constellation_id' => 20000020, 'region_id' => 10000002, 'security' => 0.9459],
                ],
                'meta' => ['limit' => 20],
            ]);
    }

    public function test_system_search_escapes_like_wildcards(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/systems?q=%25%25')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_system_show(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/systems/30002187')
            ->assertOk()
            ->assertJsonPath('data.solar_system_name', 'Amarr')
            ->assertJsonPath('data.security', 1)
            ->assertJsonStructure(['data', 'meta']);
    }

    public function test_station_search_and_system_filter(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/stations?q=moon')
            ->assertOk()
            ->assertJsonPath('meta.limit', 20)
            ->assertJsonPath('data.*.station_id', [60003466, 60003760]);

        $this->actingAs($this->user)->getJson('/api/v1/stations?q=academy&solar_system_id=30000142')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($this->user)->getJson('/api/v1/stations?q=academy&solar_system_id=abc')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_station_show_parses_moon_and_facility(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/stations/60003760')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'station_id' => 60003760,
                    'station_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
                    'moon' => 'Moon 4',
                    'facility_name' => 'Caldari Navy Assembly Plant',
                    'solar_system_id' => 30000142,
                    'region_id' => 10000002,
                    'station_type_id' => null,
                    'security' => 0.9459,
                ],
                'meta' => [],
            ]);
    }

    public function test_stations_in_system_are_paginated(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            SdeStation::create([
                'station_id' => 60100000 + $i,
                'station_name' => "Jita V - Moon {$i} - Extra Station",
                'solar_system_id' => 30000142,
                'security' => 0.9459,
            ]);
        }

        $this->actingAs($this->user)->getJson('/api/v1/systems/30000142/stations?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3])
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->user)->getJson('/api/v1/systems/30000142/stations?q=ishukone')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('meta.pagination.per_page', 50)
            ->assertJsonPath('data.0.station_id', 60003466);

        $this->actingAs($this->user)->getJson('/api/v1/systems/30000142/stations?per_page=500')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_cost_indices_list_every_activity(): void
    {
        $this->blockOutsideHttp(['*industry/systems*' => $this->costIndexPayload()]);

        $this->actingAs($this->user)->getJson('/api/v1/systems/30000142/cost-indices')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'solar_system_id' => 30000142,
                    'cost_indices' => [
                        'manufacturing' => 0.0512,
                        'researching_time_efficiency' => 0.01,
                        'researching_material_efficiency' => 0.02,
                        'copying' => 0.03,
                        'invention' => 0.0634,
                        'reaction' => 0.0221,
                    ],
                ],
                'meta' => [],
            ]);
    }

    public function test_cost_indices_zero_for_known_system_missing_from_esi_and_404_for_unknown(): void
    {
        $this->blockOutsideHttp(['*industry/systems*' => $this->costIndexPayload()]);

        $this->actingAs($this->user)->getJson('/api/v1/systems/30000144/cost-indices')
            ->assertOk()
            ->assertJsonPath('data.cost_indices.manufacturing', 0)
            ->assertJsonPath('data.cost_indices.reaction', 0);

        $this->actingAs($this->user)->getJson('/api/v1/systems/1/cost-indices')
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_cost_indices_503_when_esi_is_down(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/systems/30000142/cost-indices')
            ->assertStatus(503)
            ->assertJsonPath('code', 'service_unavailable');
    }
}
