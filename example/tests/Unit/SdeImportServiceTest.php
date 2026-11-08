<?php

namespace Tests\Unit;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\MarketGroup;
use App\Models\SdeSolarSystem;
use App\Services\SdeImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class SdeImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private SdeImportService $service;

    /** @var string|null Path to the temporary test ZIP file. */
    private ?string $testZipPath = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SdeImportService;
    }

    protected function tearDown(): void
    {
        if ($this->testZipPath && file_exists($this->testZipPath)) {
            @unlink($this->testZipPath);
        }
        parent::tearDown();
    }

    /**
     * Create a temporary ZIP file containing JSONL entries and point the service at it.
     *
     * @param  array<string, array<int, array>>  $entries  Filename => array of rows (each row is a JSON object)
     */
    private function createTestZip(array $entries): void
    {
        $this->testZipPath = tempnam(sys_get_temp_dir(), 'sde_test_').'.zip';

        $zip = new ZipArchive;
        $zip->open($this->testZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($entries as $filename => $rows) {
            $lines = array_map(fn (array $row) => json_encode($row), $rows);
            $zip->addFromString($filename, implode("\n", $lines));
        }

        $zip->close();

        $this->service->setZipPath($this->testZipPath);
    }

    // ──────────────────────────────────────────────────────────────────
    // Checksum helpers
    // ──────────────────────────────────────────────────────────────────

    public function test_get_remote_checksum_returns_hash_from_headers(): void
    {
        Http::fake([
            SdeImportService::SDE_BASE_URL => Http::response('', 200, [
                'ETag' => '"abc123"',
                'Last-Modified' => 'Wed, 01 Jan 2025 00:00:00 GMT',
                'Content-Length' => '123456',
            ]),
        ]);

        $checksum = $this->service->getRemoteChecksum();
        $this->assertNotNull($checksum);
        $this->assertEquals(
            hash('sha256', '"abc123"|Wed, 01 Jan 2025 00:00:00 GMT|123456'),
            $checksum
        );
    }

    public function test_get_remote_checksum_returns_null_on_failure(): void
    {
        Http::fake([
            SdeImportService::SDE_BASE_URL => Http::response('', 503),
        ]);

        $this->assertNull($this->service->getRemoteChecksum());
    }

    public function test_stored_checksum_is_null_before_first_import(): void
    {
        Cache::forget(SdeImportService::SDE_CHECKSUM_CACHE_KEY);
        $this->assertNull($this->service->getStoredChecksum());
    }

    public function test_store_and_retrieve_checksum(): void
    {
        $this->service->storeChecksum('deadbeef');
        $this->assertEquals('deadbeef', $this->service->getStoredChecksum());
    }

    public function test_has_update_true_when_checksums_differ(): void
    {
        Http::fake([
            SdeImportService::SDE_BASE_URL => Http::response('', 200, [
                'ETag' => '"new"',
                'Last-Modified' => 'Thu, 02 Jan 2025 00:00:00 GMT',
                'Content-Length' => '999',
            ]),
        ]);

        $this->service->storeChecksum('oldchecksum');
        $this->assertTrue($this->service->hasUpdate());
    }

    public function test_has_update_false_when_checksums_match(): void
    {
        $etag = '"same"';
        $lastModified = 'Wed, 01 Jan 2025 00:00:00 GMT';
        $contentLength = '500';
        $expected = hash('sha256', $etag.'|'.$lastModified.'|'.$contentLength);

        Http::fake([
            SdeImportService::SDE_BASE_URL => Http::response('', 200, [
                'ETag' => $etag,
                'Last-Modified' => $lastModified,
                'Content-Length' => $contentLength,
            ]),
        ]);

        $this->service->storeChecksum($expected);
        $this->assertFalse($this->service->hasUpdate());
    }

    public function test_has_update_false_when_remote_fetch_fails(): void
    {
        Http::fake([
            SdeImportService::SDE_BASE_URL => Http::response('', 503),
        ]);

        $this->service->storeChecksum('oldvalue');
        $this->assertFalse($this->service->hasUpdate());
    }

    // ──────────────────────────────────────────────────────────────────
    // importTypes
    // ──────────────────────────────────────────────────────────────────

    public function test_import_types_inserts_records(): void
    {
        $this->createTestZip([
            'types.jsonl' => [
                [
                    '_key' => 34,
                    'name' => ['en' => 'Tritanium'],
                    'groupID' => 18,
                    'portionSize' => 1,
                    'volume' => 0.01,
                    'published' => true,
                ],
                [
                    '_key' => 35,
                    'name' => ['en' => 'Pyerite'],
                    'groupID' => 18,
                    'published' => true,
                ],
            ],
        ]);

        $count = $this->service->importTypes();

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('eve_types', ['type_id' => 34, 'name' => 'Tritanium']);
        $this->assertDatabaseHas('eve_types', ['type_id' => 35, 'name' => 'Pyerite']);
    }

    public function test_import_types_upserts_existing_records(): void
    {
        EveType::create([
            'type_id' => 34,
            'name' => 'Old Name',
            'published' => false,
        ]);

        $this->createTestZip([
            'types.jsonl' => [
                ['_key' => 34, 'name' => ['en' => 'Tritanium'], 'published' => true],
            ],
        ]);

        $count = $this->service->importTypes();

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('eve_types', ['type_id' => 34, 'name' => 'Tritanium', 'published' => true]);
        $this->assertDatabaseMissing('eve_types', ['name' => 'Old Name']);
    }

    public function test_import_types_returns_zero_when_no_zip(): void
    {
        // No ZIP set — should return 0
        $count = $this->service->importTypes();
        $this->assertEquals(0, $count);
    }

    public function test_import_types_handles_plain_string_name(): void
    {
        $this->createTestZip([
            'types.jsonl' => [
                ['_key' => 34, 'name' => 'Tritanium', 'published' => true],
            ],
        ]);

        $this->service->importTypes();
        $this->assertDatabaseHas('eve_types', ['type_id' => 34, 'name' => 'Tritanium']);
    }

    // ──────────────────────────────────────────────────────────────────
    // importStations
    // ──────────────────────────────────────────────────────────────────

    public function test_import_stations_inserts_records_with_esi_names(): void
    {
        // Seed a solar system for constellation/region/name lookups
        SdeSolarSystem::create([
            'solar_system_id' => 30000142,
            'solar_system_name' => 'Jita',
            'constellation_id' => 20000020,
            'region_id' => 10000002,
            'security' => 0.9459,
        ]);

        // Seed a type name for station naming fallback
        EveType::create(['type_id' => 52678, 'name' => 'Caldari Navy Assembly Plant', 'published' => true]);

        // Mock ESI /universe/names/ to return the canonical station name
        Http::fake([
            'esi.evetech.net/latest/universe/names/*' => Http::response([
                ['id' => 60003760, 'name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant', 'category' => 'station'],
            ]),
        ]);

        $this->createTestZip([
            'npcStations.jsonl' => [
                [
                    '_key' => 60003760,
                    'solarSystemID' => 30000142,
                    'typeID' => 52678,
                    'ownerID' => 1000035,
                    'reprocessingEfficiency' => 0.5,
                ],
            ],
        ]);

        $count = $this->service->importStations();

        $this->assertEquals(1, $count);
        // After ESI resolution, station should have the full canonical name
        $this->assertDatabaseHas('sde_stations', [
            'station_id' => 60003760,
            'station_name' => 'Jita IV - Moon 4 - Caldari Navy Assembly Plant',
            'region_id' => 10000002,
        ]);
    }

    public function test_import_stations_uses_fallback_name_when_esi_fails(): void
    {
        SdeSolarSystem::create([
            'solar_system_id' => 30000142,
            'solar_system_name' => 'Jita',
            'constellation_id' => 20000020,
            'region_id' => 10000002,
            'security' => 0.9459,
        ]);

        EveType::create(['type_id' => 52678, 'name' => 'Caldari Navy Assembly Plant', 'published' => true]);

        // ESI returns a failure
        Http::fake([
            'esi.evetech.net/latest/universe/names/*' => Http::response(null, 500),
        ]);

        $this->createTestZip([
            'npcStations.jsonl' => [
                [
                    '_key' => 60003760,
                    'solarSystemID' => 30000142,
                    'typeID' => 52678,
                    'ownerID' => 1000035,
                    'reprocessingEfficiency' => 0.5,
                ],
            ],
        ]);

        $count = $this->service->importStations();

        $this->assertEquals(1, $count);
        // Falls back to "{system} - {type}" format when ESI is unavailable
        $this->assertDatabaseHas('sde_stations', [
            'station_id' => 60003760,
            'station_name' => 'Jita - Caldari Navy Assembly Plant',
            'region_id' => 10000002,
        ]);
    }

    public function test_import_stations_returns_zero_when_no_zip(): void
    {
        $this->assertEquals(0, $this->service->importStations());
    }

    // ──────────────────────────────────────────────────────────────────
    // importSolarSystems
    // ──────────────────────────────────────────────────────────────────

    public function test_import_solar_systems_inserts_records(): void
    {
        $this->createTestZip([
            'mapSolarSystems.jsonl' => [
                [
                    '_key' => 30000142,
                    'name' => ['en' => 'Jita'],
                    'constellationID' => 20000020,
                    'regionID' => 10000002,
                    'security' => 0.9459266,
                    'securityClass' => 'B',
                ],
            ],
        ]);

        $count = $this->service->importSolarSystems();

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('sde_solar_systems', [
            'solar_system_id' => 30000142,
            'solar_system_name' => 'Jita',
            'region_id' => 10000002,
        ]);
    }

    public function test_import_solar_systems_handles_plain_string_name(): void
    {
        $this->createTestZip([
            'mapSolarSystems.jsonl' => [
                [
                    '_key' => 30000142,
                    'name' => 'Jita',
                    'regionID' => 10000002,
                ],
            ],
        ]);

        $this->service->importSolarSystems();
        $this->assertDatabaseHas('sde_solar_systems', ['solar_system_id' => 30000142, 'solar_system_name' => 'Jita']);
    }

    public function test_import_solar_systems_returns_zero_when_no_zip(): void
    {
        $this->assertEquals(0, $this->service->importSolarSystems());
    }

    // ──────────────────────────────────────────────────────────────────
    // importStargates
    // ──────────────────────────────────────────────────────────────────

    public function test_import_stargates_inserts_records(): void
    {
        $this->createTestZip([
            'mapStargates.jsonl' => [
                [
                    '_key' => 50000056,
                    'solarSystemID' => 30000142,
                    'destination' => ['stargateID' => 50001668],
                    'typeID' => 29624,
                ],
            ],
        ]);

        $count = $this->service->importStargates();

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('sde_stargates', [
            'stargate_id' => 50000056,
            'solar_system_id' => 30000142,
            'destination_stargate_id' => 50001668,
        ]);
    }

    public function test_import_stargates_handles_flat_destination_id(): void
    {
        $this->createTestZip([
            'mapStargates.jsonl' => [
                [
                    '_key' => 50000056,
                    'solarSystemID' => 30000142,
                    'destinationStargateID' => 50001668,
                    'typeID' => 29624,
                ],
            ],
        ]);

        $this->service->importStargates();
        $this->assertDatabaseHas('sde_stargates', [
            'stargate_id' => 50000056,
            'destination_stargate_id' => 50001668,
        ]);
    }

    public function test_import_stargates_returns_zero_when_no_zip(): void
    {
        $this->assertEquals(0, $this->service->importStargates());
    }

    // ──────────────────────────────────────────────────────────────────
    // importTypeMaterials
    // ──────────────────────────────────────────────────────────────────

    public function test_import_type_materials_inserts_records(): void
    {
        $this->createTestZip([
            'typeMaterials.jsonl' => [
                [
                    '_key' => 18,
                    'materials' => [
                        ['materialTypeID' => 34, 'quantity' => 175],
                        ['materialTypeID' => 36, 'quantity' => 70],
                    ],
                ],
            ],
        ]);

        $count = $this->service->importTypeMaterials();

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('type_materials', [
            'type_id' => 18,
            'material_type_id' => 34,
            'quantity' => 175,
        ]);
        $this->assertDatabaseHas('type_materials', [
            'type_id' => 18,
            'material_type_id' => 36,
            'quantity' => 70,
        ]);
    }

    public function test_import_type_materials_returns_zero_when_no_zip(): void
    {
        $this->assertEquals(0, $this->service->importTypeMaterials());
    }

    // ──────────────────────────────────────────────────────────────────
    // importBlueprints
    // ──────────────────────────────────────────────────────────────────

    public function test_import_blueprints_inserts_manufacturing_activity(): void
    {
        // Seed the eve_types table so names can be resolved
        EveType::insert([
            ['type_id' => 34, 'name' => 'Tritanium', 'published' => true],
            ['type_id' => 17476, 'name' => 'Tritanium Bar', 'published' => true],
        ]);

        $this->createTestZip([
            'blueprints.jsonl' => [
                [
                    '_key' => 17477,
                    'blueprintTypeID' => 17477,
                    'activities' => [
                        'manufacturing' => [
                            'materials' => [['typeID' => 34, 'quantity' => 100]],
                            'products' => [['typeID' => 17476, 'quantity' => 1]],
                            'time' => 300,
                        ],
                    ],
                ],
            ],
        ]);

        $count = $this->service->importBlueprints();

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('blueprint_activities', [
            'blueprint_type_id' => 17477,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
        ]);

        $row = BlueprintActivity::where('blueprint_type_id', 17477)
            ->where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
            ->first();

        $this->assertNotNull($row);
        $this->assertEquals('Tritanium', $row->materials[0]['name']);
        $this->assertEquals(100, $row->materials[0]['quantity']);
        $this->assertEquals('Tritanium Bar', $row->products[0]['name']);
    }

    public function test_import_blueprints_skips_unknown_activities(): void
    {
        $this->createTestZip([
            'blueprints.jsonl' => [
                [
                    '_key' => 99999,
                    'blueprintTypeID' => 99999,
                    'activities' => [
                        'unknown_activity' => ['time' => 60],
                    ],
                ],
            ],
        ]);

        $count = $this->service->importBlueprints();
        $this->assertEquals(0, $count);
    }

    public function test_import_blueprints_uses_unknown_for_missing_type_name(): void
    {
        // eve_types is empty — no names available
        $this->createTestZip([
            'blueprints.jsonl' => [
                [
                    '_key' => 10001,
                    'blueprintTypeID' => 10001,
                    'activities' => [
                        'manufacturing' => [
                            'materials' => [['typeID' => 9999, 'quantity' => 1]],
                            'products' => [['typeID' => 8888, 'quantity' => 1]],
                            'time' => 60,
                        ],
                    ],
                ],
            ],
        ]);

        $this->service->importBlueprints();

        $row = BlueprintActivity::where('blueprint_type_id', 10001)->first();
        $this->assertNotNull($row);
        $this->assertEquals('Unknown', $row->materials[0]['name']);
        $this->assertEquals('Unknown', $row->products[0]['name']);
    }

    public function test_import_blueprints_returns_zero_when_no_zip(): void
    {
        $this->assertEquals(0, $this->service->importBlueprints());
    }

    public function test_import_blueprints_preserves_invention_probability(): void
    {
        // Seed type names
        EveType::insert([
            ['type_id' => 22229, 'name' => 'Ice Harvester II', 'published' => true],
            ['type_id' => 22228, 'name' => 'Ice Harvester I', 'published' => true],
            ['type_id' => 20176, 'name' => 'Datacore - Laser Physics', 'published' => true],
            ['type_id' => 20424, 'name' => 'Datacore - Mechanical Engineering', 'published' => true],
        ]);

        $this->createTestZip([
            'blueprints.jsonl' => [
                [
                    '_key' => 3301,
                    'blueprintTypeID' => 3301,
                    'activities' => [
                        'invention' => [
                            'materials' => [
                                ['typeID' => 20176, 'quantity' => 2],
                                ['typeID' => 20424, 'quantity' => 2],
                            ],
                            'products' => [
                                ['typeID' => 22229, 'quantity' => 10, 'probability' => 0.34],
                            ],
                            'time' => 105600,
                        ],
                    ],
                ],
            ],
        ]);

        $this->service->importBlueprints();

        $row = BlueprintActivity::where('blueprint_type_id', 3301)
            ->where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
            ->first();

        $this->assertNotNull($row);
        $this->assertCount(2, $row->materials);
        $this->assertCount(1, $row->products);
        $this->assertEquals(0.34, $row->products[0]['probability']);
        $this->assertEquals(10, $row->products[0]['quantity']);
        $this->assertEquals('Ice Harvester II', $row->products[0]['name']);
    }

    public function test_import_blueprints_stores_invention_skills(): void
    {
        // Seed type names for skills
        EveType::insert([
            ['type_id' => 22229, 'name' => 'Ice Harvester II', 'published' => true],
            ['type_id' => 23121, 'name' => 'Gallente Encryption Methods', 'published' => true],
            ['type_id' => 11448, 'name' => 'Laser Physics', 'published' => true],
            ['type_id' => 11452, 'name' => 'Mechanical Engineering', 'published' => true],
        ]);

        $this->createTestZip([
            'blueprints.jsonl' => [
                [
                    '_key' => 3301,
                    'blueprintTypeID' => 3301,
                    'activities' => [
                        'invention' => [
                            'materials' => [],
                            'products' => [
                                ['typeID' => 22229, 'quantity' => 10, 'probability' => 0.34],
                            ],
                            'skills' => [
                                ['typeID' => 23121, 'level' => 1],
                                ['typeID' => 11448, 'level' => 1],
                                ['typeID' => 11452, 'level' => 1],
                            ],
                            'time' => 105600,
                        ],
                    ],
                ],
            ],
        ]);

        $this->service->importBlueprints();

        $row = BlueprintActivity::where('blueprint_type_id', 3301)
            ->where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->skills);
        $this->assertCount(3, $row->skills);

        // Check skill data is correctly resolved
        $this->assertEquals(23121, $row->skills[0]['type_id']);
        $this->assertEquals('Gallente Encryption Methods', $row->skills[0]['name']);
        $this->assertEquals(1, $row->skills[0]['level']);

        $this->assertEquals(11448, $row->skills[1]['type_id']);
        $this->assertEquals('Laser Physics', $row->skills[1]['name']);
    }

    public function test_import_blueprints_stores_empty_skills_when_none_present(): void
    {
        EveType::insert([
            ['type_id' => 34, 'name' => 'Tritanium', 'published' => true],
            ['type_id' => 17476, 'name' => 'Tritanium Bar', 'published' => true],
        ]);

        $this->createTestZip([
            'blueprints.jsonl' => [
                [
                    '_key' => 17477,
                    'blueprintTypeID' => 17477,
                    'activities' => [
                        'manufacturing' => [
                            'materials' => [['typeID' => 34, 'quantity' => 100]],
                            'products' => [['typeID' => 17476, 'quantity' => 1]],
                            'time' => 300,
                        ],
                    ],
                ],
            ],
        ]);

        $this->service->importBlueprints();

        $row = BlueprintActivity::where('blueprint_type_id', 17477)->first();
        $this->assertNotNull($row);
        $this->assertEquals([], $row->skills);
    }

    public function test_activity_map_covers_required_activity_ids(): void
    {
        $map = SdeImportService::ACTIVITY_MAP;

        $this->assertEquals(1, $map['manufacturing']);
        $this->assertEquals(5, $map['copying']);
        $this->assertEquals(8, $map['invention']);
        $this->assertEquals(11, $map['reaction']);
    }

    // ──────────────────────────────────────────────────────────────────
    // importMarketGroups
    // ──────────────────────────────────────────────────────────────────

    public function test_import_market_groups_inserts_records(): void
    {
        $this->createTestZip([
            'marketGroups.jsonl' => [
                [
                    '_key' => 2,
                    'name' => ['en' => 'Ships'],
                    'description' => ['en' => 'All types of ships.'],
                    'parentGroupID' => null,
                    'iconID' => 22,
                    'hasTypes' => false,
                ],
                [
                    '_key' => 100,
                    'name' => ['en' => 'Frigates'],
                    'description' => ['en' => 'Small, fast ships.'],
                    'parentGroupID' => 2,
                    'iconID' => 23,
                    'hasTypes' => true,
                ],
            ],
        ]);

        $count = $this->service->importMarketGroups();

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('market_groups', [
            'market_group_id' => 2,
            'name' => 'Ships',
            'parent_group_id' => null,
            'has_types' => false,
        ]);
        $this->assertDatabaseHas('market_groups', [
            'market_group_id' => 100,
            'name' => 'Frigates',
            'parent_group_id' => 2,
            'has_types' => true,
        ]);
    }

    public function test_import_market_groups_upserts_existing_records(): void
    {
        MarketGroup::create([
            'market_group_id' => 2,
            'name' => 'Old Name',
            'has_types' => false,
        ]);

        $this->createTestZip([
            'marketGroups.jsonl' => [
                ['_key' => 2, 'name' => ['en' => 'Ships'], 'hasTypes' => false],
            ],
        ]);

        $count = $this->service->importMarketGroups();

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('market_groups', ['market_group_id' => 2, 'name' => 'Ships']);
        $this->assertDatabaseMissing('market_groups', ['name' => 'Old Name']);
    }

    public function test_import_market_groups_returns_zero_when_no_zip(): void
    {
        $this->assertEquals(0, $this->service->importMarketGroups());
    }

    public function test_import_market_groups_handles_plain_string_name(): void
    {
        $this->createTestZip([
            'marketGroups.jsonl' => [
                ['_key' => 2, 'name' => 'Ships', 'hasTypes' => false],
            ],
        ]);

        $this->service->importMarketGroups();
        $this->assertDatabaseHas('market_groups', ['market_group_id' => 2, 'name' => 'Ships']);
    }

    // ──────────────────────────────────────────────────────────────────
    // importTypes — meta_group_id
    // ──────────────────────────────────────────────────────────────────

    public function test_import_types_stores_meta_group_id(): void
    {
        $this->createTestZip([
            'types.jsonl' => [
                [
                    '_key' => 587,
                    'name' => ['en' => 'Rifter'],
                    'groupID' => 25,
                    'metaGroupID' => 1,
                    'published' => true,
                ],
                [
                    '_key' => 22228,
                    'name' => ['en' => 'Wolf'],
                    'groupID' => 25,
                    'metaGroupID' => 2,
                    'published' => true,
                ],
                [
                    '_key' => 34,
                    'name' => ['en' => 'Tritanium'],
                    'groupID' => 18,
                    'published' => true,
                ],
            ],
        ]);

        $count = $this->service->importTypes();

        $this->assertEquals(3, $count);
        $this->assertDatabaseHas('eve_types', ['type_id' => 587, 'meta_group_id' => 1]);
        $this->assertDatabaseHas('eve_types', ['type_id' => 22228, 'meta_group_id' => 2]);
        $this->assertDatabaseHas('eve_types', ['type_id' => 34, 'meta_group_id' => null]);
    }

    // ──────────────────────────────────────────────────────────────────
    // importGroups
    // ──────────────────────────────────────────────────────────────────

    public function test_import_groups_inserts_records(): void
    {
        $this->createTestZip([
            'groups.jsonl' => [
                [
                    '_key' => 25,
                    'name' => ['en' => 'Frigate'],
                    'categoryID' => 6,
                    'published' => true,
                ],
                [
                    '_key' => 26,
                    'name' => ['en' => 'Cruiser'],
                    'categoryID' => 6,
                    'published' => true,
                ],
            ],
        ]);

        $count = $this->service->importGroups();

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('inv_groups', [
            'group_id' => 25,
            'name' => 'Frigate',
            'category_id' => 6,
            'published' => true,
        ]);
        $this->assertDatabaseHas('inv_groups', [
            'group_id' => 26,
            'name' => 'Cruiser',
            'category_id' => 6,
        ]);
    }

    public function test_import_groups_returns_zero_when_no_zip(): void
    {
        $this->assertEquals(0, $this->service->importGroups());
    }

    // ──────────────────────────────────────────────────────────────────
    // importCategories
    // ──────────────────────────────────────────────────────────────────

    public function test_import_categories_inserts_records(): void
    {
        $this->createTestZip([
            'categories.jsonl' => [
                [
                    '_key' => 6,
                    'name' => ['en' => 'Ship'],
                    'published' => true,
                ],
                [
                    '_key' => 7,
                    'name' => ['en' => 'Module'],
                    'published' => true,
                ],
            ],
        ]);

        $count = $this->service->importCategories();

        $this->assertEquals(2, $count);
        $this->assertDatabaseHas('inv_categories', [
            'category_id' => 6,
            'name' => 'Ship',
            'published' => true,
        ]);
        $this->assertDatabaseHas('inv_categories', [
            'category_id' => 7,
            'name' => 'Module',
        ]);
    }

    public function test_import_categories_returns_zero_when_no_zip(): void
    {
        $this->assertEquals(0, $this->service->importCategories());
    }

    // ──────────────────────────────────────────────────────────────────
    // ZIP lifecycle
    // ──────────────────────────────────────────────────────────────────

    public function test_set_zip_path_allows_reading_entries(): void
    {
        $this->createTestZip([
            'types.jsonl' => [
                ['_key' => 1, 'name' => ['en' => 'Test'], 'published' => true],
            ],
        ]);

        $count = $this->service->importTypes();
        $this->assertEquals(1, $count);
    }

    public function test_cleanup_zip_removes_file(): void
    {
        $this->createTestZip([
            'types.jsonl' => [
                ['_key' => 1, 'name' => ['en' => 'Test'], 'published' => true],
            ],
        ]);

        $path = $this->testZipPath;
        $this->assertTrue(file_exists($path));

        $this->service->cleanupZip();
        $this->assertFalse(file_exists($path));

        // Prevent tearDown from trying to delete again
        $this->testZipPath = null;
    }
}
