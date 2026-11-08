<?php

namespace Tests\Unit;

use App\Services\EsiService;
use App\Services\PlanetaryService;
use Mockery;
use Tests\TestCase;

class PlanetaryServiceTest extends TestCase
{
    private PlanetaryService $service;

    private $esi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esi = Mockery::mock(EsiService::class);
        $this->service = new PlanetaryService($this->esi);
    }

    // ── analyzePlanet ──────────────────────────────────────────────

    public function test_analyze_planet_empty_details(): void
    {
        $result = $this->service->analyzePlanet(null, ['planet_id' => 1]);

        $this->assertEquals(0, $result['summary']['extractor_count']);
        $this->assertEquals(0, $result['summary']['factory_count']);
        $this->assertEquals(0, $result['summary']['total_pins']);
        $this->assertEmpty($result['alerts']);
    }

    public function test_analyze_planet_with_extractor(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Test Type']);
        $this->esi->shouldReceive('getPlanetarySchematic')
            ->andReturn(null);

        $details = [
            'pins' => [
                [
                    'pin_id' => 100,
                    'type_id' => 2848, // Barren ECU
                    'extractor_details' => [
                        'product_type_id' => 2268,
                        'qty_per_cycle' => 100,
                        'cycle_time' => 3600,
                        'heads' => [['head_id' => 0], ['head_id' => 1]],
                    ],
                    'expiry_time' => date('Y-m-d\TH:i:s\Z', time() + 86400),
                ],
            ],
            'routes' => [],
            'links' => [],
        ];

        $result = $this->service->analyzePlanet($details, ['planet_id' => 1]);

        $this->assertEquals(1, $result['summary']['extractor_count']);
        $this->assertCount(1, $result['outputs']);
        $this->assertEquals(100.0, $result['outputs'][0]['per_hour']);
        $this->assertEmpty($result['alerts']);
        $this->assertTrue($result['pins'][0]['extractor']['is_active']);
        $this->assertNotNull($result['pins'][0]['extractor']['expiry_time']);
    }

    public function test_analyze_planet_with_expired_extractor(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Aqueous Liquids']);

        $details = [
            'pins' => [
                [
                    'pin_id' => 100,
                    'type_id' => 2848,
                    'extractor_details' => [
                        'product_type_id' => 2268,
                        'qty_per_cycle' => 100,
                        'cycle_time' => 3600,
                        'heads' => [['head_id' => 0]],
                    ],
                    'expiry_time' => date('Y-m-d\TH:i:s\Z', time() - 3600),
                ],
            ],
            'routes' => [],
            'links' => [],
        ];

        $result = $this->service->analyzePlanet($details, ['planet_id' => 1]);

        $this->assertCount(1, $result['alerts']);
        $this->assertEquals('extractor_expired', $result['alerts'][0]['type']);
        $this->assertEquals('warning', $result['alerts'][0]['severity']);
        $this->assertFalse($result['pins'][0]['extractor']['is_active']);
    }

    public function test_analyze_planet_extractor_without_expiry_time_is_inactive(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Test Type']);

        $details = [
            'pins' => [
                [
                    'pin_id' => 100,
                    'type_id' => 2848,
                    'extractor_details' => [
                        'product_type_id' => 2268,
                        'qty_per_cycle' => 100,
                        'cycle_time' => 3600,
                        'heads' => [['head_id' => 0]],
                    ],
                ],
            ],
            'routes' => [],
            'links' => [],
        ];

        $result = $this->service->analyzePlanet($details, ['planet_id' => 1]);

        $this->assertFalse($result['pins'][0]['extractor']['is_active']);
        $this->assertNull($result['pins'][0]['extractor']['expiry_time']);
    }

    public function test_analyze_planet_with_factory(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Basic Factory']);
        $this->esi->shouldReceive('getPlanetarySchematic')
            ->with(65)
            ->andReturn([
                'schematic_name' => 'Water',
                'cycle_time' => 1800,
            ]);

        $details = [
            'pins' => [
                [
                    'pin_id' => 200,
                    'type_id' => 2473,
                    'schematic_id' => 65,
                ],
            ],
            'routes' => [],
            'links' => [],
        ];

        $result = $this->service->analyzePlanet($details, ['planet_id' => 1]);

        $this->assertEquals(1, $result['summary']['factory_count']);
        $hasFactory = false;
        foreach ($result['pins'] as $pin) {
            if (isset($pin['factory'])) {
                $hasFactory = true;
                $this->assertEquals('Water', $pin['factory']['schematic_name']);
                $this->assertEquals(1800, $pin['factory']['cycle_time']);
            }
        }
        $this->assertTrue($hasFactory);
    }

    // ── categorizePinType ──────────────────────────────────────────

    public function test_categorize_known_extractor_type(): void
    {
        $this->assertEquals(
            PlanetaryService::PIN_CATEGORY_EXTRACTOR,
            $this->service->categorizePinType(2848)
        );
    }

    public function test_categorize_known_factory_type(): void
    {
        $this->assertEquals(
            PlanetaryService::PIN_CATEGORY_BASIC_FACTORY,
            $this->service->categorizePinType(2473)
        );
    }

    public function test_categorize_unknown_type_by_name(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(99999)
            ->andReturn(['name' => 'Extractor Control Unit']);

        $this->assertEquals(
            PlanetaryService::PIN_CATEGORY_EXTRACTOR,
            $this->service->categorizePinType(99999)
        );
    }

    public function test_categorize_truly_unknown_type(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(99998)
            ->andReturn(['name' => 'Some Random Structure']);

        $this->assertEquals(
            PlanetaryService::PIN_CATEGORY_UNKNOWN,
            $this->service->categorizePinType(99998)
        );
    }

    // ── analyzeRoutes ──────────────────────────────────────────────

    public function test_analyze_routes_empty(): void
    {
        $result = $this->service->analyzeRoutes([], []);

        $this->assertEmpty($result);
    }

    public function test_analyze_routes_with_data(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2268)
            ->andReturn(['name' => 'Aqueous Liquids']);

        $routes = [
            [
                'content_type_id' => 2268,
                'quantity' => 100,
                'source_pin_id' => 1,
                'destination_pin_id' => 2,
            ],
        ];

        $result = $this->service->analyzeRoutes($routes, []);

        $this->assertCount(1, $result);
        $this->assertEquals('Aqueous Liquids', $result[0]['content_name']);
        $this->assertEquals(100, $result[0]['quantity']);
    }

    // ── buildCapacityAnalysis ──────────────────────────────────────

    public function test_build_capacity_analysis_balanced(): void
    {
        $data = [
            'characters' => [
                [
                    'character_name' => 'Test Pilot',
                    'planets' => [
                        [
                            'planet_name' => 'Planet I',
                            'planet_type' => 'temperate',
                            'summary' => [
                                'extractor_count' => 2,
                                'factory_count' => 5,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->buildCapacityAnalysis($data);

        $this->assertCount(1, $result);
        $this->assertEquals('balanced', $result[0]['status']);
    }

    public function test_build_capacity_analysis_under_capacity(): void
    {
        $data = [
            'characters' => [
                [
                    'character_name' => 'Test Pilot',
                    'planets' => [
                        [
                            'planet_name' => 'Planet I',
                            'planet_type' => 'barren',
                            'summary' => [
                                'extractor_count' => 3,
                                'factory_count' => 2,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->buildCapacityAnalysis($data);

        $this->assertCount(1, $result);
        $this->assertEquals('under_capacity', $result[0]['status']);
    }

    public function test_build_capacity_analysis_over_capacity(): void
    {
        $data = [
            'characters' => [
                [
                    'character_name' => 'Test Pilot',
                    'planets' => [
                        [
                            'planet_name' => 'Planet I',
                            'planet_type' => 'lava',
                            'summary' => [
                                'extractor_count' => 1,
                                'factory_count' => 10,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->buildCapacityAnalysis($data);

        $this->assertCount(1, $result);
        $this->assertEquals('over_capacity', $result[0]['status']);
    }

    public function test_build_capacity_analysis_idle_factories(): void
    {
        $data = [
            'characters' => [
                [
                    'character_name' => 'Test Pilot',
                    'planets' => [
                        [
                            'planet_name' => 'Planet I',
                            'planet_type' => 'gas',
                            'summary' => [
                                'extractor_count' => 0,
                                'factory_count' => 5,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->service->buildCapacityAnalysis($data);

        $this->assertCount(1, $result);
        $this->assertEquals('idle_factories', $result[0]['status']);
    }

    // ── tier labels ────────────────────────────────────────────────

    public function test_tier_labels_exist(): void
    {
        $this->assertCount(5, PlanetaryService::TIER_LABELS);
        $this->assertArrayHasKey(0, PlanetaryService::TIER_LABELS);
        $this->assertArrayHasKey(4, PlanetaryService::TIER_LABELS);
    }

    // ── computeStorageUsage ────────────────────────────────────────

    public function test_compute_storage_usage_empty_contents(): void
    {
        $pin = ['pin_id' => 1, 'type_id' => 2543, 'contents' => []];
        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        $this->assertEquals(0, $result['total_items']);
        $this->assertEquals(0.0, $result['used_volume']);
        $this->assertEquals(0, $result['usage_percent']);
        $this->assertEquals(PlanetaryService::LAUNCHPAD_CAPACITY, $result['capacity']);
        $this->assertEmpty($result['items']);
    }

    public function test_compute_storage_usage_no_contents_key(): void
    {
        $pin = ['pin_id' => 1, 'type_id' => 2543];
        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        $this->assertEquals(0, $result['total_items']);
        $this->assertEquals(0.0, $result['used_volume']);
        $this->assertEmpty($result['items']);
    }

    public function test_compute_storage_usage_with_contents(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2268)->andReturn(['name' => 'Aqueous Liquids', 'volume' => 0.01]);
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2309)->andReturn(['name' => 'Water', 'volume' => 0.38]);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2268, 'amount' => 5000],
                ['type_id' => 2309, 'amount' => 3000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        $this->assertEquals(8000, $result['total_items']);
        $this->assertCount(2, $result['items']);
        $this->assertEquals('Aqueous Liquids', $result['items'][0]['name']);
        $this->assertEquals(5000, $result['items'][0]['amount']);
        // Aqueous Liquids: 5000 * 0.01 = 50
        $this->assertEquals(50.0, $result['items'][0]['volume']);
        $this->assertEquals('Water', $result['items'][1]['name']);
        $this->assertEquals(3000, $result['items'][1]['amount']);
        // Water: 3000 * 0.38 = 1140
        $this->assertEquals(1140.0, $result['items'][1]['volume']);
        // Total: 50 + 1140 = 1190
        $this->assertEquals(1190.0, $result['used_volume']);
        $this->assertGreaterThan(0, $result['usage_percent']);
    }

    public function test_compute_storage_usage_percent_capped_at_100(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Test Item', 'volume' => 0.01]);

        // Enough items to exceed capacity at 0.01 m³ each: 12000 / 0.01 = 1,200,000
        $pin = [
            'pin_id' => 1,
            'type_id' => 2256,
            'contents' => [
                ['type_id' => 1000, 'amount' => 2000000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::STORAGE_CAPACITY);

        $this->assertEquals(100, $result['usage_percent']);
    }

    public function test_storage_capacity_constants(): void
    {
        $this->assertEquals(10000, PlanetaryService::LAUNCHPAD_CAPACITY);
        $this->assertEquals(12000, PlanetaryService::STORAGE_CAPACITY);
    }

    public function test_analyze_planet_includes_storage_for_launchpad(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Test Type', 'volume' => 0.01]);

        $details = [
            'pins' => [
                [
                    'pin_id' => 300,
                    'type_id' => 2543, // Launchpad
                    'contents' => [
                        ['type_id' => 2268, 'amount' => 1000],
                    ],
                ],
            ],
            'routes' => [],
            'links' => [],
        ];

        $result = $this->service->analyzePlanet($details, ['planet_id' => 1]);

        $this->assertEquals(1, $result['summary']['launchpad_count']);
        $launchpadPin = null;
        foreach ($result['pins'] as $pin) {
            if ($pin['category'] === PlanetaryService::PIN_CATEGORY_LAUNCHPAD) {
                $launchpadPin = $pin;
                break;
            }
        }
        $this->assertNotNull($launchpadPin);
        $this->assertArrayHasKey('storage', $launchpadPin);
        $this->assertEquals(PlanetaryService::LAUNCHPAD_CAPACITY, $launchpadPin['storage']['capacity']);
        $this->assertEquals(1000, $launchpadPin['storage']['total_items']);
    }

    public function test_analyze_planet_includes_storage_for_storage_facility(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Test Type']);

        $details = [
            'pins' => [
                [
                    'pin_id' => 400,
                    'type_id' => 2257, // Ice Storage Facility
                    'contents' => [],
                ],
            ],
            'routes' => [],
            'links' => [],
        ];

        $result = $this->service->analyzePlanet($details, ['planet_id' => 1]);

        $this->assertEquals(1, $result['summary']['storage_count']);
        $storagePin = null;
        foreach ($result['pins'] as $pin) {
            if ($pin['category'] === PlanetaryService::PIN_CATEGORY_STORAGE) {
                $storagePin = $pin;
                break;
            }
        }
        $this->assertNotNull($storagePin);
        $this->assertArrayHasKey('storage', $storagePin);
        $this->assertEquals(PlanetaryService::STORAGE_CAPACITY, $storagePin['storage']['capacity']);
    }

    // ── resolveTypeVolume (via computeStorageUsage) ────────────────

    public function test_storage_uses_esi_volume_data(): void
    {
        // ESI returns volume=0.38 for a P2 product
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2390)->andReturn(['name' => 'Enriched Uranium', 'volume' => 0.38]);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2390, 'amount' => 1000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // 1000 * 0.38 = 380 m³
        $this->assertEquals(380.0, $result['used_volume']);
        $this->assertEquals(380.0, $result['items'][0]['volume']);
    }

    public function test_storage_falls_back_to_default_volume(): void
    {
        // ESI returns no volume field
        $this->esi->shouldReceive('getTypeInfo')
            ->with(9999)->andReturn(['name' => 'Unknown Stuff']);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 9999, 'amount' => 1000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // 1000 * 0.01 = 10 m³ (default)
        $this->assertEquals(10.0, $result['used_volume']);
    }

    public function test_default_volume_constant(): void
    {
        $this->assertEquals(0.01, PlanetaryService::DEFAULT_VOLUME);
    }

    // ── Extraction formula constants ───────────────────────────────

    public function test_extraction_decay_factor_constant(): void
    {
        $this->assertEquals(0.012, PlanetaryService::DECAY_FACTOR);
    }

    public function test_extraction_noise_factor_constant(): void
    {
        $this->assertEquals(0.8, PlanetaryService::NOISE_FACTOR);
    }

    // ── calculateExtractorCycleValues ──────────────────────────────

    public function test_calculate_extractor_cycle_values_returns_correct_count(): void
    {
        $values = $this->service->calculateExtractorCycleValues(6965, 1800, 30);

        $this->assertCount(30, $values);
    }

    public function test_calculate_extractor_cycle_values_first_cycle_matches_formula(): void
    {
        $qtyPerCycle = 6965;
        $cycleTime = 1800; // 30 minutes
        $values = $this->service->calculateExtractorCycleValues($qtyPerCycle, $cycleTime, 1);

        // bar_width = 1800 / 900 = 2.0
        // t = 0.5 * 2.0 = 1.0
        // decay_value = 6965 / (1 + 1.0 * 0.012) = 6965 / 1.012 ≈ 6882.41
        // phase_shift = pow(6965, 0.7) ≈ 466.87
        // sin_a = cos(466.87 + 1.0 * (1/12))
        // sin_b = cos(233.435 + 1.0 * 0.2)
        // sin_c = cos(0.5)
        // The exact value depends on trig, but it must be > 0 and a reasonable number
        $this->assertCount(1, $values);
        $this->assertGreaterThan(0, $values[0]);
        // First cycle output should be in a reasonable range around the base qty
        $this->assertGreaterThan($qtyPerCycle * 0.5, $values[0]);
        $this->assertLessThan($qtyPerCycle * 5, $values[0]);
    }

    public function test_calculate_extractor_cycle_values_decay_reduces_output(): void
    {
        // Over many cycles, the average output per cycle should decrease
        $values = $this->service->calculateExtractorCycleValues(6965, 1800, 100);

        $firstTen = array_sum(array_slice($values, 0, 10));
        $lastTen = array_sum(array_slice($values, 90, 10));

        // Due to decay, later cycles should produce less than earlier ones on average
        $this->assertGreaterThan($lastTen, $firstTen);
    }

    public function test_calculate_extractor_cycle_values_zero_inputs(): void
    {
        $this->assertEmpty($this->service->calculateExtractorCycleValues(0, 1800, 30));
        $this->assertEmpty($this->service->calculateExtractorCycleValues(6965, 0, 30));
        $this->assertEmpty($this->service->calculateExtractorCycleValues(6965, 1800, 0));
    }

    public function test_calculate_extractor_cycle_values_negative_inputs(): void
    {
        $this->assertEmpty($this->service->calculateExtractorCycleValues(-1, 1800, 30));
        $this->assertEmpty($this->service->calculateExtractorCycleValues(6965, -1, 30));
        $this->assertEmpty($this->service->calculateExtractorCycleValues(6965, 1800, -1));
    }

    // ── calculateExtractorTotals ───────────────────────────────────

    public function test_calculate_extractor_totals_returns_expected_keys(): void
    {
        $result = $this->service->calculateExtractorTotals(6965, 1800, 54000);

        $this->assertArrayHasKey('total_output', $result);
        $this->assertArrayHasKey('avg_per_hour', $result);
        $this->assertArrayHasKey('total_cycles', $result);
    }

    public function test_calculate_extractor_totals_correct_cycle_count(): void
    {
        // 171000 seconds / 1800 seconds = 95 cycles
        $result = $this->service->calculateExtractorTotals(6965, 1800, 171000);

        $this->assertEquals(95, $result['total_cycles']);
    }

    public function test_calculate_extractor_totals_total_output_positive(): void
    {
        $result = $this->service->calculateExtractorTotals(6965, 1800, 171000);

        $this->assertGreaterThan(0, $result['total_output']);
        $this->assertGreaterThan(0, $result['avg_per_hour']);
    }

    public function test_calculate_extractor_totals_avg_decreases_over_longer_duration(): void
    {
        $qtyPerCycle = 6965;
        $cycleTime = 1800;

        // Shorter program
        $shortResult = $this->service->calculateExtractorTotals($qtyPerCycle, $cycleTime, 54000);
        // Longer program (more decay)
        $longResult = $this->service->calculateExtractorTotals($qtyPerCycle, $cycleTime, 345600);

        // Over a longer extraction, decay causes the average rate to be lower
        $this->assertGreaterThan($longResult['avg_per_hour'], $shortResult['avg_per_hour']);
    }

    public function test_calculate_extractor_totals_zero_duration(): void
    {
        $result = $this->service->calculateExtractorTotals(6965, 1800, 0);

        $this->assertEquals(0, $result['total_output']);
        $this->assertEquals(0.0, $result['avg_per_hour']);
        $this->assertEquals(0, $result['total_cycles']);
    }

    // ── analyzePlanet with extraction formula ──────────────────────

    public function test_analyze_planet_extractor_includes_decay_fields(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Test Type']);

        $installTime = date('Y-m-d\TH:i:s\Z', time() - 86400);
        $expiryTime = date('Y-m-d\TH:i:s\Z', time() + 86400);

        $details = [
            'pins' => [
                [
                    'pin_id' => 100,
                    'type_id' => 2848,
                    'extractor_details' => [
                        'product_type_id' => 2268,
                        'qty_per_cycle' => 6965,
                        'cycle_time' => 1800,
                        'heads' => [['head_id' => 0], ['head_id' => 1]],
                    ],
                    'install_time' => $installTime,
                    'expiry_time' => $expiryTime,
                ],
            ],
            'routes' => [],
            'links' => [],
        ];

        $result = $this->service->analyzePlanet($details, ['planet_id' => 1]);

        $extractor = $result['pins'][0]['extractor'];
        $this->assertArrayHasKey('avg_per_hour', $extractor);
        $this->assertArrayHasKey('total_output', $extractor);
        $this->assertArrayHasKey('total_cycles', $extractor);
        $this->assertArrayHasKey('install_time', $extractor);
        $this->assertGreaterThan(0, $extractor['avg_per_hour']);
        $this->assertGreaterThan(0, $extractor['total_output']);
        $this->assertGreaterThan(0, $extractor['total_cycles']);
    }

    public function test_analyze_planet_extractor_without_install_time_has_zero_totals(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->andReturn(['name' => 'Test Type']);

        $details = [
            'pins' => [
                [
                    'pin_id' => 100,
                    'type_id' => 2848,
                    'extractor_details' => [
                        'product_type_id' => 2268,
                        'qty_per_cycle' => 6965,
                        'cycle_time' => 1800,
                        'heads' => [['head_id' => 0]],
                    ],
                    'expiry_time' => date('Y-m-d\TH:i:s\Z', time() + 86400),
                ],
            ],
            'routes' => [],
            'links' => [],
        ];

        $result = $this->service->analyzePlanet($details, ['planet_id' => 1]);

        $extractor = $result['pins'][0]['extractor'];
        // Without install_time, duration is 0, so avg/total should be 0
        $this->assertEquals(0.0, $extractor['avg_per_hour']);
        $this->assertEquals(0, $extractor['total_output']);
    }
}
