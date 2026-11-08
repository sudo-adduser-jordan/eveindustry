<?php

namespace Tests\Feature\ApiV1\Heavy;

use App\Models\Character;
use App\Models\EveType;
use App\Models\MoonScanResult;
use App\Models\MoonScanSystem;
use App\Models\SdeSolarSystem;
use App\Models\User;
use App\Models\WholemapSignature;
use App\Models\WormholeConnection;
use App\Models\WormholeJumpLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Heavy\HeavyFixtures;
use Tests\TestCase;

class MoonScanWholemapTest extends TestCase
{
    use HeavyFixtures, RefreshDatabase;

    private const LOCATION_SCOPE = 'esi-location.read_location.v1';

    private function scan(): string
    {
        return "Moon\tMoon Product\tQuantity\tOre TypeID\n"
            ."Aldali II - Moon 12\t\t\t\n"
            ."\tZeolites\t0.5\t45490\n"
            ."Aldali II - Moon 3\t\t\t\n"
            ."\tZeolites\t1\t45490\n";
    }

    private function stored(int $corporationId, string $name = 'Aldali'): MoonScanSystem
    {
        $system = MoonScanSystem::create([
            'system_name' => $name, 'corporation_id' => $corporationId, 'region_id' => 10000002,
            'pricing_mode' => 'raw', 'last_scanned_at' => '2026-10-20 10:00:00',
        ]);
        foreach ([1 => 500, 2 => 900, 3 => 700] as $n => $value) {
            $system->moons()->create(['moon_name' => "$name II - Moon $n", 'planet_name' => "$name II", 'grade' => 'B', 'total_value' => $value,
                'ores' => [], 'scanned_at' => '2026-10-20 10:00:00']);
        }

        return $system;
    }

    private function connectionFor(User $user): WormholeConnection
    {
        $signature = WholemapSignature::create([
            'user_id' => $user->id, 'signature_id' => 'ABC-123', 'system_id' => 31000005, 'system_name' => 'J123456', 'type' => 'wormhole',
        ]);

        return WormholeConnection::create([
            'user_id' => $user->id, 'signature_id' => $signature->id, 'from_system_id' => 31000005, 'from_system_name' => 'J123456',
        ]);
    }

    public function test_requires_auth(): void
    {
        $this->postJson('/api/v1/moon-scans', [])->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        foreach (['/moon-scans/systems', '/moon-scans/systems/Aldali', '/wholemap/location', '/wholemap/connections/1/jump-logs'] as $path) {
            $this->getJson('/api/v1'.$path)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_analyse_validates(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/moon-scans', [])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('scan_data');
        $this->actingAs($user)->postJson('/api/v1/moon-scans', ['scan_data' => 'x', 'pricing_mode' => 'bogus', 'region_id' => 1])
            ->assertStatus(422)->assertJsonValidationErrors(['pricing_mode', 'region_id']);
    }

    public function test_analyse_stores_and_returns_201_with_freshness(): void
    {
        EveType::forceCreate(['type_id' => 45490, 'name' => 'Zeolites', 'group_id' => 1, 'volume' => 10]);
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $response = $this->actingAs($user)->postJson('/api/v1/moon-scans', ['scan_data' => $this->scan(), 'pricing_mode' => 'refined', 'region_id' => 10000043])
            ->assertCreated()
            ->assertJsonPath('data.pricing_mode', 'refined')
            ->assertJsonPath('data.errors', [])
            ->assertJsonCount(2, 'data.moons')
            ->assertJsonStructure(['data' => ['moons' => [['id', 'name', 'grade', 'total_value', 'ores', 'occupied', 'occupying_corporation_id', 'occupying_corporation_name']]], 'meta' => ['freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale']]]);
        $this->assertNotNull($response->json('data.moons.0.id'));

        $system = MoonScanSystem::firstOrFail();
        $this->assertSame(98000001, $system->corporation_id);
        $this->assertSame(10000043, $system->region_id);
        $this->assertSame('refined', $system->pricing_mode);
        $this->assertSame(2, MoonScanResult::count());
    }

    public function test_analyse_with_nothing_usable_is_200_and_stores_nothing(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);

        $this->actingAs($user)->postJson('/api/v1/moon-scans', ['scan_data' => "\tOre\t1\t34"])
            ->assertOk()->assertJsonPath('data.moons', [])->assertJsonCount(1, 'data.errors');
        $this->assertSame(0, MoonScanSystem::count());
    }

    public function test_systems_list_is_corporation_scoped_and_paginated(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);
        $this->stored(98000001, 'Zorg');
        $this->stored(98000001, 'Aldali');
        $this->stored(98000099, 'Hidden');

        $this->actingAs($user)->getJson('/api/v1/moon-scans/systems')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Aldali', 'Zorg'])
            ->assertJsonPath('data.0.last_scanned_at', '2026-10-20T10:00:00+00:00')
            ->assertJsonPath('meta.pagination.total', 2);

        $this->actingAs($this->makeUser())->getJson('/api/v1/moon-scans/systems')->assertOk()->assertJsonPath('data', []);
    }

    public function test_system_moons_by_value_with_meta_and_repricing(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);
        $this->stored(98000001);

        $this->actingAs($user)->getJson('/api/v1/moon-scans/systems/Aldali?per_page=2')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Aldali II - Moon 2', 'Aldali II - Moon 3'])
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('meta.system', ['name' => 'Aldali', 'region_id' => 10000002, 'pricing_mode' => 'raw', 'scanned_at' => '2026-10-20T10:00:00+00:00'])
            ->assertJsonStructure(['meta' => ['freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale']]]);

        $this->actingAs($user)->getJson('/api/v1/moon-scans/systems/Aldali?pricing_mode=compressed')
            ->assertOk()->assertJsonPath('meta.system.pricing_mode', 'compressed');
        $this->actingAs($user)->getJson('/api/v1/moon-scans/systems/Aldali?pricing_mode=bogus')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    }

    public function test_system_of_another_corporation_is_404_and_no_character_is_422(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user);
        $this->stored(98000099, 'Aldali');

        $this->actingAs($user)->getJson('/api/v1/moon-scans/systems/Aldali')->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->actingAs($this->makeUser())->getJson('/api/v1/moon-scans/systems/Aldali')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('message', 'No corporation found.');
    }

    public function test_jump_logs_newest_first_paginated(): void
    {
        $user = $this->makeUser();
        $connection = $this->connectionFor($user);
        foreach (['2026-10-20 10:00:00' => 'First', '2026-10-21 10:00:00' => 'Second', '2026-10-22 10:00:00' => 'Third'] as $at => $pilot) {
            WormholeJumpLog::create(['connection_id' => $connection->id, 'user_id' => $user->id, 'character_id' => 9001, 'character_name' => $pilot,
                'ship_type_id' => 587, 'ship_type_name' => 'Rifter', 'ship_mass' => 1200000.5, 'jumped_at' => $at]);
        }

        $response = $this->actingAs($user)->getJson("/api/v1/wholemap/connections/{$connection->id}/jump-logs?per_page=2")
            ->assertOk()
            ->assertJsonPath('data.*.character_name', ['Third', 'Second'])
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.0.jumped_at', '2026-10-22T10:00:00+00:00');
        $this->assertSame(['id', 'character_id', 'character_name', 'ship_type_id', 'ship_type_name', 'ship_mass', 'jumped_at'], array_keys($response->json('data.0')));
    }

    public function test_jump_logs_of_another_users_connection_is_403_and_unknown_is_404(): void
    {
        $connection = $this->connectionFor($this->makeUser());
        $other = $this->makeUser();

        $this->actingAs($other)->getJson("/api/v1/wholemap/connections/{$connection->id}/jump-logs")
            ->assertStatus(403)->assertJsonPath('code', 'forbidden');
        $this->actingAs($other)->getJson('/api/v1/wholemap/connections/9999/jump-logs')
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
    }

    public function test_location_resolves_and_caches_on_the_character(): void
    {
        SdeSolarSystem::forceCreate(['solar_system_id' => 30000142, 'solar_system_name' => 'Jita', 'region_id' => 10000002, 'security' => 0.9, 'security_class' => 'B']);
        $user = $this->makeUser();
        $character = $this->makeCharacter($user, ['scopes' => [self::LOCATION_SCOPE]]);
        $this->mockEsi()->shouldReceive('fetchPilotCurrentPosition')->once()
            ->withArgs(fn (Character $c, bool $ignoreCache = false) => $c->is($character) && $ignoreCache === true)
            ->andReturn(['solar_system_id' => 30000142]);

        $this->actingAs($user)->getJson('/api/v1/wholemap/location')
            ->assertOk()
            ->assertExactJson(['data' => [
                'solar_system_id' => 30000142, 'solar_system_name' => 'Jita', 'security' => 0.9, 'security_class' => 'B', 'region_id' => 10000002,
            ], 'meta' => []]);
        $this->assertSame('Jita', $character->fresh()->cached_location);
    }

    public function test_location_errors(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user)->getJson('/api/v1/wholemap/location')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonPath('message', 'No main character linked.');

        $this->makeCharacter($user);
        $user = $user->fresh();
        $this->actingAs($user)->getJson('/api/v1/wholemap/location')
            ->assertStatus(422)->assertJsonPath('message', 'Location scope not granted.');
        $this->actingAs($user)->getJson('/api/v1/wholemap/location?character_id=1')
            ->assertStatus(422)->assertJsonPath('message', 'Character not found.');
        $this->actingAs($user)->getJson('/api/v1/wholemap/location?character_id=abc')
            ->assertStatus(422)->assertJsonValidationErrors('character_id');
    }

    public function test_location_esi_failure_is_502(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, ['scopes' => [self::LOCATION_SCOPE]]);
        $this->mockEsi()->shouldReceive('fetchPilotCurrentPosition')->once()->andReturn(null);

        $this->actingAs($user)->getJson('/api/v1/wholemap/location')
            ->assertStatus(502)->assertJsonPath('code', 'server_error')->assertJsonPath('message', 'Unable to fetch location.');
    }
}
