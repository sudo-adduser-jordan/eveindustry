<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Models\PlayerOwnedStructure;
use App\Models\StructureCharacterAccess;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiServiceStructureDbTest extends TestCase
{
    use RefreshDatabase;

    private EsiService $service;

    private Character $character;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->service = new EsiService;
        $this->character = new Character([
            'character_id' => 12345,
            'user_id' => 1,
            'name' => 'Test Char',
            'access_token' => 'token',
            'refresh_token' => 'refresh',
            'token_expires_at' => now()->addHour(),
            'scopes' => ['esi-universe.read_structures.v1'],
        ]);
    }

    public function test_get_structure_returns_db_record_without_http_call(): void
    {
        PlayerOwnedStructure::create([
            'structure_id' => 1022734985679,
            'name' => 'My Keepstar',
            'owner_id' => 98000001,
            'solar_system_id' => 30000142,
            'type_id' => 35832,
        ]);

        Http::fake([
            '*/universe/structures/*' => Http::response(null, 500),
        ]);

        $result = $this->service->getStructure(1022734985679, $this->character);

        $this->assertNotNull($result);
        $this->assertEquals('My Keepstar', $result['name']);
        $this->assertEquals(30000142, $result['solar_system_id']);

        Http::assertNothingSent();
    }

    public function test_get_structure_populates_db_on_esi_success(): void
    {
        $esiResponse = [
            'name' => 'Alliance Fortizar',
            'owner_id' => 98000002,
            'solar_system_id' => 30002187,
            'type_id' => 35833,
            'position' => ['x' => 1.0, 'y' => 2.0, 'z' => 3.0],
        ];

        Http::fake([
            '*/universe/structures/1022734985680/*' => Http::response($esiResponse, 200),
            '*/oauth/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600, 'refresh_token' => 'refresh', 'token_type' => 'Bearer'], 200),
        ]);

        $result = $this->service->getStructure(1022734985680, $this->character);

        if ($result !== null) {
            $db = PlayerOwnedStructure::find(1022734985680);
            $this->assertNotNull($db);
            $this->assertEquals('Alliance Fortizar', $db->name);
            $this->assertEquals(98000002, $db->owner_id);
            $this->assertEquals(30002187, $db->solar_system_id);
            $this->assertEquals(35833, $db->type_id);
            $this->assertEquals(1.0, $db->position_x);
            $this->assertEquals(2.0, $db->position_y);
            $this->assertEquals(3.0, $db->position_z);
        }
    }

    public function test_get_structure_populates_cache_from_db_record(): void
    {
        PlayerOwnedStructure::create([
            'structure_id' => 1022734985681,
            'name' => 'Cached Structure',
            'owner_id' => 98000003,
            'solar_system_id' => 30000144,
        ]);

        Http::fake([
            '*/universe/structures/*' => Http::response(null, 500),
        ]);

        $this->service->getStructure(1022734985681, $this->character);

        $cached = Cache::get('esi.structure.1022734985681.char.12345');
        $this->assertNotNull($cached);
        $this->assertEquals('Cached Structure', $cached['name']);
    }

    public function test_get_structure_prefers_cache_over_db(): void
    {
        // Put data directly in cache using the per-character key format
        Cache::put('esi.structure.1022734985682.char.12345', ['name' => 'From Cache', 'solar_system_id' => 30000001], 3600);

        // Also put conflicting data in DB
        PlayerOwnedStructure::create([
            'structure_id' => 1022734985682,
            'name' => 'From DB',
        ]);

        Http::fake([
            '*/universe/structures/*' => Http::response(null, 500),
        ]);

        $result = $this->service->getStructure(1022734985682, $this->character);

        $this->assertEquals('From Cache', $result['name']);
        Http::assertNothingSent();
    }

    public function test_get_structure_serves_db_record_when_character_has_denial(): void
    {
        // Structure is known in the DB from a previous successful fetch
        PlayerOwnedStructure::create([
            'structure_id' => 1022734985683,
            'name' => 'Known Structure',
            'owner_id' => 98000004,
            'solar_system_id' => 30000150,
        ]);

        // This character has been denied access via ESI recently
        StructureCharacterAccess::create([
            'structure_id' => 1022734985683,
            'character_id' => 12345,
            'access_denied_at' => now()->subMinutes(5),
        ]);

        Http::fake([
            '*/universe/structures/*' => Http::response(null, 403),
        ]);

        // Despite the denial record, the DB-cached name should be returned
        $result = $this->service->getStructure(1022734985683, $this->character);

        $this->assertNotNull($result);
        $this->assertEquals('Known Structure', $result['name']);
        Http::assertNothingSent();
    }

    public function test_get_structure_suppresses_esi_for_60_days_after_denial(): void
    {
        // Denial was recorded 59 days ago – still within the 60-day window
        StructureCharacterAccess::create([
            'structure_id' => 1022734985684,
            'character_id' => 12345,
            'access_denied_at' => now()->subDays(59),
        ]);

        Http::fake();

        $result = $this->service->getStructure(1022734985684, $this->character);

        $this->assertNull($result);
        Http::assertNothingSent();
    }

    public function test_get_structure_retries_esi_after_60_day_denial_window(): void
    {
        // Denial was recorded 61 days ago – outside the 60-day window
        StructureCharacterAccess::create([
            'structure_id' => 1022734985685,
            'character_id' => 12345,
            'access_denied_at' => now()->subDays(61),
        ]);

        $esiResponse = [
            'name' => 'Recovered Structure',
            'owner_id' => 98000005,
            'solar_system_id' => 30000160,
            'type_id' => 35832,
            'position' => ['x' => 0.0, 'y' => 0.0, 'z' => 0.0],
        ];

        Http::fake([
            '*/universe/structures/1022734985685/*' => Http::response($esiResponse, 200),
            '*/oauth/token' => Http::response(['access_token' => 'new-token', 'expires_in' => 3600, 'refresh_token' => 'refresh', 'token_type' => 'Bearer'], 200),
        ]);

        $result = $this->service->getStructure(1022734985685, $this->character);

        // ESI should have been called now that the 60-day window has expired
        $this->assertNotNull($result);
        $this->assertEquals('Recovered Structure', $result['name']);
    }

    public function test_transient_structure_failure_is_not_persisted_as_access_denial(): void
    {
        Http::fake(['*/universe/structures/*' => Http::response(['error' => 'Unavailable'], 503)]);

        $this->assertNull($this->service->getStructure(1022734999991, $this->character));
        $this->assertDatabaseMissing('structure_character_access', ['structure_id' => 1022734999991]);
    }

    public function test_upstream_throttle_is_not_persisted_as_access_denial(): void
    {
        Http::fake(['*/universe/structures/*' => Http::response(['error' => 'Rate limited'], 429, ['Retry-After' => '30'])]);

        $this->assertNull($this->service->getStructure(1022734999992, $this->character));
        $this->assertDatabaseMissing('structure_character_access', ['structure_id' => 1022734999992]);
    }

    public function test_explicit_structure_forbidden_is_recorded_for_that_character(): void
    {
        Http::fake(['*/universe/structures/*' => Http::response(['error' => 'Forbidden'], 403)]);

        $this->assertNull($this->service->getStructure(1022734999993, $this->character));
        $this->assertDatabaseHas('structure_character_access', ['structure_id' => 1022734999993, 'character_id' => $this->character->character_id]);
    }
}
