<?php

namespace Tests\Feature\ApiV1\Planning;

use App\Models\StationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Planning\PlanningFixtures;
use Tests\TestCase;

class StationConfigAndMoonTest extends TestCase
{
    use PlanningFixtures, RefreshDatabase;

    private function body(array $over = []): array
    {
        return array_merge(['name' => 'Home', 'structure_type' => 'raitaru', 'rig_me' => 't1', 'rig_te' => 'none', 'system_security' => 'highsec'], $over);
    }

    public function test_logged_out(): void
    {
        $this->getJson('/api/v1/station-configs')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        $this->putJson('/api/v1/moon-scans/moons/1/occupation', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
    }

    public function test_config_lifecycle(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $this->postJson('/api/v1/station-configs', $this->body(['is_default' => true]))
            ->assertStatus(201)->assertJsonPath('data.name', 'Home')->assertJsonPath('data.is_default', true);
        $first = StationConfig::first();
        $this->assertSame('manufacturing', $first->activity_type);

        $this->postJson('/api/v1/station-configs', $this->body(['name' => 'Alt', 'is_default' => true]))->assertStatus(201);
        $this->assertFalse($first->fresh()->is_default);

        $this->makeStationConfig($this->makeUser(), ['name' => 'zzz']);
        $this->makeStationConfig($user, ['name' => 'Mining', 'activity_type' => 'reaction']);
        $list = $this->getJson('/api/v1/station-configs')->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $this->assertSame(['Alt', 'Home'], array_column($list->json('data'), 'name'));

        $this->putJson("/api/v1/station-configs/{$first->id}", ['name' => 'Renamed', 'is_default' => true])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.is_default', true);
        $this->assertSame(1, StationConfig::where('user_id', $user->id)->where('activity_type', 'manufacturing')->where('is_default', true)->count());

        $alt = StationConfig::where('name', 'Alt')->first();
        $this->postJson("/api/v1/station-configs/{$alt->id}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->assertFalse($first->fresh()->is_default);

        $this->deleteJson("/api/v1/station-configs/{$alt->id}")->assertStatus(204);
        $this->assertDatabaseMissing('station_configs', ['id' => $alt->id]);
    }

    public function test_config_422_and_other_users_config_is_404(): void
    {
        $this->actingAs($this->makeUser());
        $this->postJson('/api/v1/station-configs', ['structure_type' => 'x'])->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['name', 'structure_type', 'rig_me', 'rig_te', 'system_security']);

        $cfg = $this->makeStationConfig($this->makeUser());
        $this->putJson("/api/v1/station-configs/{$cfg->id}", ['name' => 'x'])->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->deleteJson("/api/v1/station-configs/{$cfg->id}")->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->postJson("/api/v1/station-configs/{$cfg->id}/default")->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->assertDatabaseHas('station_configs', ['id' => $cfg->id, 'name' => 'Home Raitaru', 'is_default' => false]);
    }

    public function test_moon_occupation(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);
        $this->actingAs($user);
        $moon = $this->makeMoon();
        $url = "/api/v1/moon-scans/moons/{$moon->id}/occupation";

        $this->putJson($url, ['occupied' => true, 'corporation_id' => 123, 'corporation_name' => 'Rivals'])
            ->assertOk()->assertJsonPath('data.occupied', true)->assertJsonPath('data.occupying_corporation_name', 'Rivals');
        $this->assertDatabaseHas('moon_scan_results', ['id' => $moon->id, 'occupied' => true, 'occupying_corporation_id' => 123]);

        $this->putJson($url, ['occupied' => false, 'corporation_id' => 123])
            ->assertOk()->assertJsonPath('data.occupied', false)->assertJsonPath('data.occupying_corporation_id', null);
        $this->assertDatabaseHas('moon_scan_results', ['id' => $moon->id, 'occupied' => false, 'occupying_corporation_id' => null]);

        $this->putJson($url, [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_moon_occupation_other_corporation_missing_and_no_corporation(): void
    {
        $moon = $this->makeMoon(98000099);
        $url = "/api/v1/moon-scans/moons/{$moon->id}/occupation";

        $this->actingAs($this->makeUser());
        $this->putJson($url, ['occupied' => true])->assertStatus(422);

        $user = $this->makeUser();
        $this->makeCharacter($user);
        $this->actingAs($user);
        $this->putJson($url, ['occupied' => true])->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->putJson('/api/v1/moon-scans/moons/99999/occupation', ['occupied' => true])->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->assertDatabaseHas('moon_scan_results', ['id' => $moon->id, 'occupied' => false]);
    }
}
