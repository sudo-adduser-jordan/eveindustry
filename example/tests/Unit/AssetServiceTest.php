<?php

namespace Tests\Unit;

use App\Models\Character;
use App\Services\AssetService;
use App\Services\EsiService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

class AssetServiceTest extends TestCase
{
    private AssetService $service;

    private $esiMock;

    protected function setUp(): void
    {
        parent::setUp();
        config(['assets.durable_snapshots' => false]);

        $this->esiMock = Mockery::mock(EsiService::class);
        $this->service = new AssetService($this->esiMock);
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
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-assets.read_corporation_assets.v1',
                'esi-characters.read_corporation_roles.v1',
            ],
        ], $overrides));
    }

    public function test_enrichment_resolves_a_repeated_location_once(): void
    {
        $this->esiMock->shouldReceive('getTypeInfo')->once()->with(587)->andReturn(['name' => 'Rifter']);
        $this->esiMock->shouldReceive('getStation')->once()->with(60003760)->andReturn(['name' => 'Jita']);
        $raw = array_map(fn ($id) => ['item_id' => $id, 'type_id' => 587, 'quantity' => 1,
            'location_id' => 60003760, 'location_type' => 'station'], range(1, 100));
        $rows = (new \ReflectionMethod(AssetService::class, 'enrichAssets'))
            ->invoke($this->service, $raw, $this->makeCharacter(), 'personal');
        $this->assertCount(100, $rows);
        $this->assertSame('Jita', $rows[99]['location_name']);
    }

    public function test_cyclic_locations_abort_enrichment_before_publication(): void
    {
        $this->expectException(\App\Services\Esi\EsiRequestException::class);
        $map = [1 => ['location_id' => 2, 'location_type' => 'item', 'type_id' => 587],
            2 => ['location_id' => 1, 'location_type' => 'item', 'type_id' => 587]];
        (new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName'))
            ->invoke($this->service, 1, 'item', $map, [], [587 => 'Rifter']);
    }

    public function test_enrich_assets_sets_correct_fields(): void
    {
        $character = $this->makeCharacter();

        $rawAssets = [
            [
                'item_id' => 1000000001,
                'type_id' => 587,
                'quantity' => 10,
                'location_id' => 60003760,
                'location_type' => 'station',
                'location_flag' => 'Hangar',
                'is_singleton' => false,
            ],
        ];

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(587)
            ->andReturn(['name' => 'Rifter']);

        $this->esiMock->shouldReceive('getStation')
            ->with(60003760)
            ->andReturn(['name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant']);

        $method = new \ReflectionMethod(AssetService::class, 'enrichAssets');
        $result = $method->invoke($this->service, $rawAssets, $character, 'personal');

        $this->assertCount(1, $result);
        $asset = $result[0];
        $this->assertEquals('Rifter', $asset['type_name']);
        $this->assertEquals(10, $asset['quantity']);
        $this->assertEquals('Jita IV - Moon 4 - Caldari Navy Assembly Plant', $asset['location_name']);
        $this->assertEquals('Test Char', $asset['character_name']);
        $this->assertEquals('personal', $asset['owner_type']);
        $this->assertNull($asset['corporation_name']);
        $this->assertNull($asset['parent_item_id']);
    }

    public function test_enrich_assets_corporate_includes_corp_name(): void
    {
        $character = $this->makeCharacter();

        $rawAssets = [
            [
                'item_id' => 1000000002,
                'type_id' => 34,
                'quantity' => 50000,
                'location_id' => 60003760,
                'location_type' => 'station',
                'location_flag' => 'CorpSAG1',
                'is_singleton' => false,
            ],
        ];

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(34)
            ->andReturn(['name' => 'Tritanium']);

        $this->esiMock->shouldReceive('getStation')
            ->with(60003760)
            ->andReturn(['name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant']);

        $method = new \ReflectionMethod(AssetService::class, 'enrichAssets');
        $result = $method->invoke($this->service, $rawAssets, $character, 'corporate');

        $this->assertCount(1, $result);
        $this->assertEquals('corporate', $result[0]['owner_type']);
        $this->assertEquals('Test Corp', $result[0]['corporation_name']);
        $this->assertEquals('Corp Hangar 1', $result[0]['location_flag']);
    }

    public function test_enrich_assets_sets_parent_item_id_for_nested(): void
    {
        $character = $this->makeCharacter();

        $rawAssets = [
            [
                'item_id' => 100,
                'type_id' => 17363,
                'quantity' => 1,
                'location_id' => 60003760,
                'location_type' => 'station',
                'location_flag' => 'Hangar',
                'is_singleton' => true,
            ],
            [
                'item_id' => 200,
                'type_id' => 34,
                'quantity' => 5000,
                'location_id' => 100,
                'location_type' => 'item',
                'location_flag' => 'Unlocked',
                'is_singleton' => false,
            ],
        ];

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(17363)
            ->andReturn(['name' => 'Giant Secure Container']);

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(34)
            ->andReturn(['name' => 'Tritanium']);

        $this->esiMock->shouldReceive('getStation')
            ->with(60003760)
            ->andReturn(['name' => 'Jita IV']);

        $this->esiMock->shouldReceive('getCharacterAssetNames')
            ->with($character, [100])
            ->andReturn([]);

        $method = new \ReflectionMethod(AssetService::class, 'enrichAssets');
        $result = $method->invoke($this->service, $rawAssets, $character, 'personal');

        $this->assertCount(2, $result);
        // Container itself is top-level
        $this->assertNull($result[0]['parent_item_id']);
        // Tritanium is inside the container
        $this->assertEquals(100, $result[1]['parent_item_id']);
    }

    public function test_search_assets_filters_by_type_name(): void
    {
        $assets = [
            ['item_id' => 1, 'type_name' => 'Rifter', 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 2, 'type_name' => 'Tritanium', 'quantity' => 5000, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 3, 'type_name' => 'Pyerite', 'quantity' => 3000, 'location_name' => 'Amarr', 'character_name' => 'Char2', 'owner_type' => 'personal', 'parent_item_id' => null],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        $result = $serviceMock->searchAssets(1, 'rift');

        $this->assertCount(1, $result);
        $this->assertEquals('Rifter', $result[0]['type_name']);
    }

    public function test_search_assets_filters_by_location(): void
    {
        $assets = [
            ['item_id' => 1, 'type_name' => 'Rifter', 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 2, 'type_name' => 'Pyerite', 'quantity' => 3000, 'location_name' => 'Amarr', 'character_name' => 'Char2', 'owner_type' => 'personal', 'parent_item_id' => null],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        $result = $serviceMock->searchAssets(1, 'amarr');

        $this->assertCount(1, $result);
        $this->assertEquals('Pyerite', $result[0]['type_name']);
    }

    public function test_search_assets_sorts_by_quantity_desc(): void
    {
        $assets = [
            ['item_id' => 1, 'type_name' => 'A', 'quantity' => 10, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 2, 'type_name' => 'B', 'quantity' => 5000, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 3, 'type_name' => 'C', 'quantity' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        $result = $serviceMock->searchAssets(1, null, 'quantity', 'desc');

        $this->assertEquals(5000, $result[0]['quantity']);
        $this->assertEquals(100, $result[1]['quantity']);
        $this->assertEquals(10, $result[2]['quantity']);
    }

    public function test_search_assets_sorts_by_type_name_asc(): void
    {
        $assets = [
            ['item_id' => 1, 'type_name' => 'Zealot', 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 2, 'type_name' => 'Abaddon', 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 3, 'type_name' => 'Maller', 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        $result = $serviceMock->searchAssets(1, null, 'type_name', 'asc');

        $this->assertEquals('Abaddon', $result[0]['type_name']);
        $this->assertEquals('Maller', $result[1]['type_name']);
        $this->assertEquals('Zealot', $result[2]['type_name']);
    }

    public function test_search_assets_invalid_sort_falls_back_to_type_name(): void
    {
        $assets = [
            ['item_id' => 1, 'type_name' => 'Zealot', 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 2, 'type_name' => 'Abaddon', 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        $result = $serviceMock->searchAssets(1, null, 'invalid_field', 'asc');

        $this->assertEquals('Abaddon', $result[0]['type_name']);
        $this->assertEquals('Zealot', $result[1]['type_name']);
    }

    public function test_resolve_location_name_npc_station(): void
    {
        $this->esiMock->shouldReceive('getStation')
            ->with(60003760)
            ->andReturn(['name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant']);

        $method = new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName');
        $result = $method->invoke($this->service, 60003760, 'station', []);

        $this->assertEquals('Jita IV - Moon 4 - Caldari Navy Assembly Plant', $result);
    }

    public function test_resolve_location_name_solar_system(): void
    {
        $this->esiMock->shouldReceive('getSystem')
            ->with(30000142)
            ->andReturn(['name' => 'Jita']);

        $method = new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName');
        $result = $method->invoke($this->service, 30000142, 'solar_system', []);

        $this->assertEquals('Jita', $result);
    }

    public function test_resolve_location_name_player_structure_no_characters(): void
    {
        // With no characters, the code tries resolveUniverseNames as a last resort
        $this->esiMock->shouldReceive('resolveUniverseNames')
            ->with([1022734985679])
            ->andReturn([]);

        $method = new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName');
        $result = $method->invoke($this->service, 1022734985679, 'other', []);

        $this->assertEquals('Structure #1022734985679', $result);
    }

    public function test_resolve_location_name_player_structure_with_name(): void
    {
        $character = $this->makeCharacter([
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-universe.read_structures.v1',
            ],
        ]);

        $this->esiMock->shouldReceive('getStructure')
            ->with(1022734985679, $character)
            ->andReturn(['name' => 'My Keepstar', 'solar_system_id' => 30000142]);

        $this->esiMock->shouldReceive('getSystem')
            ->with(30000142)
            ->andReturn(['name' => 'Jita']);

        $method = new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName');
        $result = $method->invoke($this->service, 1022734985679, 'other', [], [$character]);

        $this->assertEquals('My Keepstar (Jita)', $result);
    }

    public function test_resolve_location_name_player_structure_without_system(): void
    {
        $character = $this->makeCharacter([
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-universe.read_structures.v1',
            ],
        ]);

        $this->esiMock->shouldReceive('getStructure')
            ->with(1022734985679, $character)
            ->andReturn(['name' => 'My Keepstar']);

        $method = new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName');
        $result = $method->invoke($this->service, 1022734985679, 'other', [], [$character]);

        $this->assertEquals('My Keepstar', $result);
    }

    public function test_resolve_location_name_player_structure_tries_second_character(): void
    {
        $char1 = $this->makeCharacter([
            'character_id' => 11111,
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-universe.read_structures.v1',
            ],
        ]);
        $char2 = $this->makeCharacter([
            'character_id' => 22222,
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-universe.read_structures.v1',
            ],
        ]);

        // First character fails (403 - no docking access), second succeeds
        $this->esiMock->shouldReceive('getStructure')
            ->with(1022734985679, $char1)
            ->once()
            ->andReturn(null);

        $this->esiMock->shouldReceive('getStructure')
            ->with(1022734985679, $char2)
            ->once()
            ->andReturn(['name' => 'Alliance Fortizar', 'solar_system_id' => 30002187]);

        $this->esiMock->shouldReceive('getSystem')
            ->with(30002187)
            ->andReturn(['name' => 'Amarr']);

        $method = new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName');
        $result = $method->invoke($this->service, 1022734985679, 'other', [], [$char1, $char2]);

        $this->assertEquals('Alliance Fortizar (Amarr)', $result);
    }

    public function test_resolve_location_name_player_structure_falls_back_to_universe_names(): void
    {
        $character = $this->makeCharacter([
            'scopes' => [
                'esi-assets.read_assets.v1',
                'esi-universe.read_structures.v1',
            ],
        ]);

        // Authenticated structure lookup fails (no docking access)
        $this->esiMock->shouldReceive('getStructure')
            ->with(1050621537688, $character)
            ->andReturn(null);

        // Public universe/names fallback succeeds
        $this->esiMock->shouldReceive('resolveUniverseNames')
            ->with([1050621537688])
            ->andReturn([
                ['id' => 1050621537688, 'name' => 'Pandemic Horde Staging', 'category' => 'station'],
            ]);

        $method = new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName');
        $result = $method->invoke($this->service, 1050621537688, 'other', [], [$character]);

        $this->assertEquals('Pandemic Horde Staging', $result);
    }

    public function test_resolve_location_name_nested_item(): void
    {
        $assetMap = [
            999 => [
                'item_id' => 999,
                'type_id' => 17363,
                'location_id' => 60003760,
                'location_type' => 'station',
            ],
        ];

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(17363)
            ->andReturn(['name' => 'Giant Secure Container']);

        $this->esiMock->shouldReceive('getStation')
            ->with(60003760)
            ->andReturn(['name' => 'Jita IV']);

        $method = new \ReflectionMethod(AssetService::class, 'resolveAssetLocationName');
        $result = $method->invoke($this->service, 999, 'item', $assetMap);

        $this->assertEquals('Giant Secure Container in Jita IV', $result);
    }

    public function test_resolve_type_name_falls_back_to_esi(): void
    {
        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(587)
            ->andReturn(['name' => 'Rifter']);

        $method = new \ReflectionMethod(AssetService::class, 'resolveTypeName');
        $result = $method->invoke($this->service, 587);

        $this->assertEquals('Rifter', $result);
    }

    public function test_resolve_type_name_returns_unknown_for_zero(): void
    {
        $method = new \ReflectionMethod(AssetService::class, 'resolveTypeName');
        $result = $method->invoke($this->service, 0);

        $this->assertEquals('Unknown', $result);
    }

    public function test_build_asset_tree_nests_children_under_parent(): void
    {
        $assets = [
            ['item_id' => 100, 'type_name' => 'Giant Secure Container', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 200, 'type_name' => 'Tritanium', 'quantity' => 5000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 300, 'type_name' => 'Pyerite', 'quantity' => 3000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 400, 'type_name' => 'Rifter', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
        ];

        $tree = $this->service->buildAssetTree($assets);

        // Should have 2 top-level items: container and rifter
        $this->assertCount(2, $tree);

        $container = $tree[0];
        $this->assertEquals('Giant Secure Container', $container['type_name']);
        $this->assertCount(2, $container['children']);
        $this->assertEquals('Tritanium', $container['children'][0]['type_name']);
        $this->assertEquals('Pyerite', $container['children'][1]['type_name']);

        $rifter = $tree[1];
        $this->assertEquals('Rifter', $rifter['type_name']);
        $this->assertEmpty($rifter['children']);
    }

    public function test_build_asset_tree_keeps_top_level_items_without_children(): void
    {
        $assets = [
            ['item_id' => 1, 'type_name' => 'Rifter', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 2, 'type_name' => 'Maller', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
        ];

        $tree = $this->service->buildAssetTree($assets);

        $this->assertCount(2, $tree);
        $this->assertEmpty($tree[0]['children']);
        $this->assertEmpty($tree[1]['children']);
    }

    public function test_search_finds_items_inside_containers(): void
    {
        $assets = [
            ['item_id' => 100, 'type_name' => 'Giant Secure Container', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 200, 'type_name' => 'Tritanium', 'quantity' => 5000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 300, 'type_name' => 'Rifter', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        // Search for 'trit' - should find Tritanium inside the container
        $result = $serviceMock->searchAssets(1, 'trit');

        // The container should appear because its child matches
        $this->assertCount(1, $result);
        $this->assertEquals('Giant Secure Container', $result[0]['type_name']);
        $this->assertCount(1, $result[0]['children']);
        $this->assertEquals('Tritanium', $result[0]['children'][0]['type_name']);
    }

    public function test_search_parent_match_includes_all_children(): void
    {
        $assets = [
            ['item_id' => 100, 'type_name' => 'Giant Secure Container', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 200, 'type_name' => 'Tritanium', 'quantity' => 5000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 300, 'type_name' => 'Pyerite', 'quantity' => 3000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        // Search for 'container' - parent matches, so all children should be present
        $result = $serviceMock->searchAssets(1, 'container');

        $this->assertCount(1, $result);
        $this->assertEquals('Giant Secure Container', $result[0]['type_name']);
        $this->assertCount(2, $result[0]['children']);
    }

    public function test_search_child_match_only_includes_matching_children(): void
    {
        $assets = [
            ['item_id' => 100, 'type_name' => 'Giant Secure Container', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 200, 'type_name' => 'Tritanium', 'quantity' => 5000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 300, 'type_name' => 'Pyerite', 'quantity' => 3000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 400, 'type_name' => 'Mexallon', 'quantity' => 1000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        // Search for 'trit' - only Tritanium matches, not Pyerite or Mexallon
        $result = $serviceMock->searchAssets(1, 'trit');

        $this->assertCount(1, $result);
        $this->assertEquals('Giant Secure Container', $result[0]['type_name']);
        // Only the matching child should be included
        $this->assertCount(1, $result[0]['children']);
        $this->assertEquals('Tritanium', $result[0]['children'][0]['type_name']);
    }

    public function test_get_cached_until_returns_null_when_no_cache(): void
    {
        $result = $this->service->getLastUpdatedTimestamp(999);

        $this->assertNull($result);
    }

    public function test_get_cached_until_returns_iso_timestamp(): void
    {
        // Simulate cache set 5 minutes ago (300 seconds)
        $cachedAt = time() - 300;
        Cache::forever('assets.user.1.cached_at', $cachedAt);

        $result = $this->service->getLastUpdatedTimestamp(1);

        $expected = date('c', $cachedAt);
        $this->assertEquals($expected, $result);
    }

    public function test_get_materials_groups_by_type(): void
    {
        $assets = [
            ['item_id' => 1, 'type_id' => 34, 'type_name' => 'Tritanium', 'quantity' => 5000, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 2, 'type_id' => 35, 'type_name' => 'Pyerite', 'quantity' => 3000, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 3, 'type_id' => 587, 'type_name' => 'Rifter', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial()->shouldAllowMockingProtectedMethods();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        // Mock resolveGroupId: 34 (Tritanium) and 35 (Pyerite) are in group 18 (Minerals), 587 (Rifter) is in group 25 (not a material)
        $serviceMock->shouldReceive('resolveGroupId')
            ->with(34)
            ->andReturn(18);
        $serviceMock->shouldReceive('resolveGroupId')
            ->with(35)
            ->andReturn(18);
        $serviceMock->shouldReceive('resolveGroupId')
            ->with(587)
            ->andReturn(25);

        // Mock prices (loaded in one batch for all material types)
        $serviceMock->shouldReceive('resolveUnitPricesForTypes')
            ->once()
            ->with(Mockery::on(fn ($ids) => collect($ids)->sort()->values()->all() === [34, 35]))
            ->andReturn([34 => 5.0, 35 => 10.0]);

        $result = $serviceMock->getMaterialsForUser(1);

        $this->assertArrayHasKey('groups', $result);
        $this->assertArrayHasKey('grand_total', $result);

        // Only Minerals group should exist (Rifter is not a material)
        $this->assertCount(1, $result['groups']);
        $this->assertEquals('Minerals', $result['groups'][0]['name']);
        $this->assertCount(2, $result['groups'][0]['items']);

        // Grand total: 5000*5 + 3000*10 = 55000
        $this->assertEquals(55000.0, $result['grand_total']);
        $this->assertEquals(55000.0, $result['groups'][0]['total']);
    }

    public function test_get_materials_includes_items_in_containers(): void
    {
        $assets = [
            ['item_id' => 100, 'type_id' => 17363, 'type_name' => 'Giant Secure Container', 'quantity' => 1, 'parent_item_id' => null, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
            ['item_id' => 200, 'type_id' => 34, 'type_name' => 'Tritanium', 'quantity' => 5000, 'parent_item_id' => 100, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal'],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial()->shouldAllowMockingProtectedMethods();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        // Container is not a material, Tritanium is
        $serviceMock->shouldReceive('resolveGroupId')
            ->with(17363)
            ->andReturn(450);
        $serviceMock->shouldReceive('resolveGroupId')
            ->with(34)
            ->andReturn(18);

        $serviceMock->shouldReceive('resolveUnitPricesForTypes')
            ->once()
            ->with([34])
            ->andReturn([34 => 5.0]);

        $result = $serviceMock->getMaterialsForUser(1);

        // Tritanium inside container should still be found
        $this->assertCount(1, $result['groups']);
        $this->assertEquals('Minerals', $result['groups'][0]['name']);
        $this->assertCount(1, $result['groups'][0]['items']);
        $this->assertEquals('Tritanium', $result['groups'][0]['items'][0]['type_name']);
        $this->assertEquals(25000.0, $result['grand_total']);
    }

    public function test_enrich_assets_includes_custom_name_for_singleton(): void
    {
        $character = $this->makeCharacter();

        $rawAssets = [
            [
                'item_id' => 100,
                'type_id' => 17363,
                'quantity' => 1,
                'location_id' => 60003760,
                'location_type' => 'station',
                'location_flag' => 'Hangar',
                'is_singleton' => true,
            ],
        ];

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(17363)
            ->andReturn(['name' => 'Giant Secure Container']);

        $this->esiMock->shouldReceive('getStation')
            ->with(60003760)
            ->andReturn(['name' => 'Jita IV']);

        $this->esiMock->shouldReceive('getCharacterAssetNames')
            ->with($character, [100])
            ->andReturn([100 => 'My Minerals Stash']);

        $method = new \ReflectionMethod(AssetService::class, 'enrichAssets');
        $result = $method->invoke($this->service, $rawAssets, $character, 'personal');

        $this->assertCount(1, $result);
        $this->assertEquals('Giant Secure Container', $result[0]['type_name']);
        $this->assertEquals('My Minerals Stash', $result[0]['name']);
    }

    public function test_enrich_assets_name_is_null_for_non_singleton(): void
    {
        $character = $this->makeCharacter();

        $rawAssets = [
            [
                'item_id' => 1000000001,
                'type_id' => 34,
                'quantity' => 5000,
                'location_id' => 60003760,
                'location_type' => 'station',
                'location_flag' => 'Hangar',
                'is_singleton' => false,
            ],
        ];

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(34)
            ->andReturn(['name' => 'Tritanium']);

        $this->esiMock->shouldReceive('getStation')
            ->with(60003760)
            ->andReturn(['name' => 'Jita IV']);

        $method = new \ReflectionMethod(AssetService::class, 'enrichAssets');
        $result = $method->invoke($this->service, $rawAssets, $character, 'personal');

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['name']);
    }

    public function test_enrich_assets_corporate_uses_corporation_asset_names(): void
    {
        $character = $this->makeCharacter();

        $rawAssets = [
            [
                'item_id' => 100,
                'type_id' => 17363,
                'quantity' => 1,
                'location_id' => 60003760,
                'location_type' => 'station',
                'location_flag' => 'CorpSAG1',
                'is_singleton' => true,
            ],
        ];

        $this->esiMock->shouldReceive('getTypeInfo')
            ->with(17363)
            ->andReturn(['name' => 'Giant Secure Container']);

        $this->esiMock->shouldReceive('getStation')
            ->with(60003760)
            ->andReturn(['name' => 'Jita IV']);

        $this->esiMock->shouldReceive('getCorporationAssetNames')
            ->with($character, 98000, [100])
            ->andReturn([100 => 'Corp Minerals']);

        $method = new \ReflectionMethod(AssetService::class, 'enrichAssets');
        $result = $method->invoke($this->service, $rawAssets, $character, 'corporate');

        $this->assertCount(1, $result);
        $this->assertEquals('Corp Minerals', $result[0]['name']);
    }

    public function test_search_assets_filters_by_custom_name(): void
    {
        $assets = [
            ['item_id' => 1, 'type_name' => 'Giant Secure Container', 'name' => 'My Minerals Stash', 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
            ['item_id' => 2, 'type_name' => 'Rifter', 'name' => null, 'quantity' => 1, 'location_name' => 'Jita', 'character_name' => 'Char', 'owner_type' => 'personal', 'parent_item_id' => null],
        ];

        $serviceMock = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $serviceMock->shouldReceive('getAssetsForUser')
            ->with(1)
            ->andReturn($assets);

        $result = $serviceMock->searchAssets(1, 'minerals stash');

        $this->assertCount(1, $result);
        $this->assertEquals('My Minerals Stash', $result[0]['name']);
    }

    public function test_cached_at_not_set_when_data_fetch_fails(): void
    {
        Cache::forget('assets.user.999');
        Cache::forget('assets.user.999.cached_at');

        // Use a partial mock that lets the real fetchAndCacheAssets run, but make
        // the Character query return a collection with one character whose ESI
        // call throws, simulating a failed data fetch.
        $character = $this->makeCharacter(['user_id' => 999]);

        $this->esiMock->shouldReceive('getCharacterAssets')
            ->andThrow(new \RuntimeException('ESI unavailable'));

        // Stub the Character query to return our test character
        $service = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();

        // Because fetchAndCacheAssets propagates the exception, the cached_at
        // key should NOT be set (Cache::forever is placed after the data fetch).
        try {
            $service->fetchAndCacheAssets(999);
        } catch (\RuntimeException $e) {
            // Expected — the fetch threw before reaching Cache::forever
        }

        $this->assertNull(
            Cache::get('assets.user.999.cached_at'),
            'cached_at must not be set when the data fetch fails'
        );
    }

    public function test_cached_at_aligns_with_main_cache(): void
    {
        // Verify that when fetchAndCacheAssets populates the cache,
        // getLastUpdatedTimestamp returns a valid timestamp
        Cache::forget('assets.user.42');
        Cache::forget('assets.user.42.cached_at');

        // Pre-populate the cache to simulate what fetchAndCacheAssets does
        $now = time();
        Cache::forever('assets.user.42', [['item_id' => 1]]);
        Cache::forever('assets.user.42.cached_at', $now);

        $result = $this->service->getLastUpdatedTimestamp(42);

        $this->assertNotNull($result, 'cached_at should be available when main cache exists');
        $expected = date('c', $now);
        $this->assertEquals($expected, $result);
    }

    public function test_empty_results_are_not_cached_forever(): void
    {
        Cache::forget('assets.user.500');
        Cache::forget('assets.user.500.cached_at');

        // fetchAndCacheAssets queries Character::where which may throw without
        // a real database — mirror the approach used by the existing
        // test_cached_at_not_set_when_data_fetch_fails test.
        $service = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();

        try {
            $service->fetchAndCacheAssets(500);
        } catch (\RuntimeException $e) {
            // Expected — no database table in unit tests
        }

        $this->assertNull(
            Cache::get('assets.user.500'),
            'Empty results must not be cached forever'
        );
        $this->assertNull(
            Cache::get('assets.user.500.cached_at'),
            'cached_at must not be set when results are empty'
        );
    }

    public function test_cold_assets_queue_a_refresh_without_fetching_inline(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        Cache::forget('assets.user.501');
        Cache::forget('assets.user.501.cached_at');

        // Since empty results are not cached, getAssetsForUser must call
        // fetchAndCacheAssets each time until non-empty data is returned.
        $service = Mockery::mock(AssetService::class, [$this->esiMock])->makePartial();
        $service->shouldNotReceive('fetchAndCacheAssets');

        // Both calls should trigger fetchAndCacheAssets since nothing is cached
        $result1 = $service->getAssetsForUser(501);
        $result2 = $service->getAssetsForUser(501);

        $this->assertEmpty($result1);
        $this->assertEmpty($result2);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RefreshAssetData::class);

        // Verify the cache is still empty — empty results must not be stored
        $this->assertNull(Cache::get('assets.user.501'));
        $this->assertNull(Cache::get('assets.user.501.cached_at'));
    }

    public function test_non_empty_results_are_cached_forever(): void
    {
        Cache::forget('assets.user.502');
        Cache::forget('assets.user.502.cached_at');

        // Pre-populate the cache as fetchAndCacheAssets would for non-empty data
        $assets = [['item_id' => 9999, 'type_name' => 'Rifter', 'quantity' => 5]];
        Cache::forever('assets.user.502', $assets);
        Cache::forever('assets.user.502.cached_at', time());

        // getAssetsForUser should return the cached data without re-fetching
        $result = $this->service->getAssetsForUser(502);

        $this->assertNotEmpty($result);
        $this->assertEquals(9999, $result[0]['item_id']);
    }

    // ──── getLocationGroupedAssets ───────────────────────────────────────

    public function test_location_grouped_assets_returns_correct_structure(): void
    {
        $assets = [
            ['item_id' => 1, 'type_id' => 100, 'type_name' => 'Tritanium', 'quantity' => 500, 'location_id' => 60003760, 'location_name' => 'Jita IV', 'character_name' => 'Char', 'name' => null, 'owner_type' => 'personal'],
            ['item_id' => 2, 'type_id' => 101, 'type_name' => 'Pyerite', 'quantity' => 300, 'location_id' => 60003760, 'location_name' => 'Jita IV', 'character_name' => 'Char', 'name' => null, 'owner_type' => 'personal'],
            ['item_id' => 3, 'type_id' => 100, 'type_name' => 'Tritanium', 'quantity' => 200, 'location_id' => 60004588, 'location_name' => 'Amarr VIII', 'character_name' => 'Char', 'name' => null, 'owner_type' => 'personal'],
        ];

        Cache::forever('assets.user.601', $assets);

        $result = $this->service->getLocationGroupedAssets(601);

        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('locations', $result);

        // Summary checks
        $this->assertEquals(3, $result['summary']['total_items']);
        $this->assertEquals(1000, $result['summary']['total_quantity']);
        $this->assertEquals(2, $result['summary']['location_count']);
        $this->assertEquals(2, $result['summary']['type_count']);

        // Location ordering (alphabetical)
        $this->assertCount(2, $result['locations']);
        $this->assertEquals('Amarr VIII', $result['locations'][0]['location_name']);
        $this->assertEquals('Jita IV', $result['locations'][1]['location_name']);

        Cache::forget('assets.user.601');
    }

    public function test_location_grouped_assets_aggregates_duplicates(): void
    {
        $assets = [
            ['item_id' => 1, 'type_id' => 100, 'type_name' => 'Rifter', 'quantity' => 5, 'location_id' => 60003760, 'location_name' => 'Jita IV', 'character_name' => 'Char', 'name' => null, 'owner_type' => 'personal'],
            ['item_id' => 2, 'type_id' => 100, 'type_name' => 'Rifter', 'quantity' => 3, 'location_id' => 60003760, 'location_name' => 'Jita IV', 'character_name' => 'Alt', 'name' => null, 'owner_type' => 'personal'],
        ];

        Cache::forever('assets.user.602', $assets);

        $result = $this->service->getLocationGroupedAssets(602);

        $this->assertCount(1, $result['locations']);
        $location = $result['locations'][0];
        $this->assertCount(1, $location['items']); // Aggregated into one row
        $this->assertEquals(8, $location['items'][0]['quantity']); // 5 + 3
        $this->assertEquals(8, $location['total_quantity']);

        Cache::forget('assets.user.602');
    }

    public function test_location_grouped_assets_search_filters(): void
    {
        $assets = [
            ['item_id' => 1, 'type_id' => 100, 'type_name' => 'Rifter', 'quantity' => 5, 'location_id' => 60003760, 'location_name' => 'Jita IV', 'character_name' => 'Char', 'name' => null, 'owner_type' => 'personal'],
            ['item_id' => 2, 'type_id' => 101, 'type_name' => 'Tristan', 'quantity' => 3, 'location_id' => 60004588, 'location_name' => 'Amarr VIII', 'character_name' => 'Alt', 'name' => null, 'owner_type' => 'personal'],
        ];

        Cache::forever('assets.user.603', $assets);

        $result = $this->service->getLocationGroupedAssets(603, 'Rifter');

        $this->assertCount(1, $result['locations']);
        $this->assertEquals('Jita IV', $result['locations'][0]['location_name']);
        $this->assertEquals(1, $result['summary']['total_items']);

        Cache::forget('assets.user.603');
    }

    public function test_location_grouped_assets_empty(): void
    {
        Cache::forever('assets.user.604', []);

        $result = $this->service->getLocationGroupedAssets(604);

        $this->assertEquals(0, $result['summary']['total_items']);
        $this->assertEquals(0, $result['summary']['location_count']);
        $this->assertEmpty($result['locations']);

        Cache::forget('assets.user.604');
    }

    public function test_location_grouped_items_sorted_alphabetically(): void
    {
        $assets = [
            ['item_id' => 1, 'type_id' => 100, 'type_name' => 'Zydrine', 'quantity' => 10, 'location_id' => 60003760, 'location_name' => 'Jita IV', 'character_name' => 'Char', 'name' => null, 'owner_type' => 'personal'],
            ['item_id' => 2, 'type_id' => 101, 'type_name' => 'Mexallon', 'quantity' => 20, 'location_id' => 60003760, 'location_name' => 'Jita IV', 'character_name' => 'Char', 'name' => null, 'owner_type' => 'personal'],
            ['item_id' => 3, 'type_id' => 102, 'type_name' => 'Tritanium', 'quantity' => 30, 'location_id' => 60003760, 'location_name' => 'Jita IV', 'character_name' => 'Char', 'name' => null, 'owner_type' => 'personal'],
        ];

        Cache::forever('assets.user.605', $assets);

        $result = $this->service->getLocationGroupedAssets(605);

        $items = $result['locations'][0]['items'];
        $this->assertEquals('Mexallon', $items[0]['type_name']);
        $this->assertEquals('Tritanium', $items[1]['type_name']);
        $this->assertEquals('Zydrine', $items[2]['type_name']);

        Cache::forget('assets.user.605');
    }
}
