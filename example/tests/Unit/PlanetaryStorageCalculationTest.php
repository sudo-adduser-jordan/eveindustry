<?php

namespace Tests\Unit;

use App\Models\EveType;
use App\Services\EsiService;
use App\Services\PlanetaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tests for planetary storage calculations.
 *
 * Based on actual in-game data:
 * - Launchpad capacity: 10,000 m³
 * - Storage facility capacity: 12,000 m³
 *
 * PI Commodity volumes per unit:
 * - P0 (Raw): 0.01 m³
 * - P1 (Processed): 0.19 m³ (verified: 24940 units = 4739 m³)
 * - P2 (Refined): 1.5 m³
 * - P3 (Specialized): 6.0 m³
 * - P4 (Advanced): 100.0 m³
 */
class PlanetaryStorageCalculationTest extends TestCase
{
    use RefreshDatabase;

    private PlanetaryService $service;
    private $esi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->esi = Mockery::mock(EsiService::class);
        $this->service = new PlanetaryService($this->esi);
    }

    // ══════════════════════════════════════════════════════════════════
    // P1 COMMODITY VOLUME TESTS (Toxic Metals example: 24940 units = 4739 m³)
    // ══════════════════════════════════════════════════════════════════

    public function test_p1_toxic_metals_volume_calculation(): void
    {
        // Real-world test case: 24940 units of Toxic Metals = 4739 m³
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2400)
            ->andReturn(['name' => 'Toxic Metals']);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2400, 'amount' => 24940],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // 24940 * 0.19 = 4738.6 m³ (rounds to 4738.6, close to 4739)
        $this->assertEquals(24940, $result['total_items']);
        $this->assertEqualsWithDelta(4738.6, $result['used_volume'], 1.0);
        $this->assertEqualsWithDelta(47.4, $result['usage_percent'], 0.5);
    }

    public function test_p1_volume_per_unit_is_0_19(): void
    {
        // Verify P1 volume: 1000 units should = 190 m³
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2400)
            ->andReturn(['name' => 'Toxic Metals']);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2400, 'amount' => 1000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // 1000 * 0.19 = 190 m³
        $this->assertEquals(190.0, $result['used_volume']);
        $this->assertEquals(190.0, $result['items'][0]['volume']);
    }

    public function test_all_p1_commodities_use_correct_volume(): void
    {
        $p1Commodities = [
            2389 => 'Plasmoids',
            2390 => 'Electrolytes',
            2392 => 'Oxidizing Compound',
            2393 => 'Bacteria',
            2395 => 'Proteins',
            2396 => 'Biofuels',
            2397 => 'Industrial Fibers',
            2398 => 'Reactive Metals',
            2399 => 'Precious Metals',
            2400 => 'Toxic Metals',
            2401 => 'Chiral Structures',
            3683 => 'Oxygen',
            3779 => 'Biomass',
            9828 => 'Silicon',
            3645 => 'Water',
        ];

        foreach ($p1Commodities as $typeId => $name) {
            $this->esi->shouldReceive('getTypeInfo')
                ->with($typeId)
                ->andReturn(['name' => $name]);

            $pin = [
                'pin_id' => 1,
                'type_id' => 2543,
                'contents' => [
                    ['type_id' => $typeId, 'amount' => 1000],
                ],
            ];

            $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

            $this->assertEquals(
                190.0,
                $result['used_volume'],
                "P1 commodity {$name} (ID: {$typeId}) should have volume of 190 m³ for 1000 units"
            );
        }
    }

    // ══════════════════════════════════════════════════════════════════
    // P0 COMMODITY VOLUME TESTS (Raw materials: 0.01 m³/unit)
    // ══════════════════════════════════════════════════════════════════

    public function test_p0_raw_materials_volume(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2267)
            ->andReturn(['name' => 'Aqueous Liquids']);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2267, 'amount' => 100000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // 100000 * 0.01 = 1000 m³
        $this->assertEquals(100000, $result['total_items']);
        $this->assertEquals(1000.0, $result['used_volume']);
        $this->assertEquals(10.0, $result['usage_percent']);
    }

    // ══════════════════════════════════════════════════════════════════
    // P2 COMMODITY VOLUME TESTS (Refined: 1.5 m³/unit)
    // ══════════════════════════════════════════════════════════════════

    public function test_p2_refined_commodities_volume(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2312)
            ->andReturn(['name' => 'Supertensile Plastics']);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2312, 'amount' => 1000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // 1000 * 1.5 = 1500 m³
        $this->assertEquals(1000, $result['total_items']);
        $this->assertEquals(1500.0, $result['used_volume']);
        $this->assertEquals(15.0, $result['usage_percent']);
    }

    // ══════════════════════════════════════════════════════════════════
    // P3 COMMODITY VOLUME TESTS (Specialized: 6.0 m³/unit)
    // ══════════════════════════════════════════════════════════════════

    public function test_p3_specialized_commodities_volume(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2344)
            ->andReturn(['name' => 'Condensates']);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2344, 'amount' => 100],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // 100 * 6.0 = 600 m³
        $this->assertEquals(100, $result['total_items']);
        $this->assertEquals(600.0, $result['used_volume']);
        $this->assertEquals(6.0, $result['usage_percent']);
    }

    // ══════════════════════════════════════════════════════════════════
    // P4 COMMODITY VOLUME TESTS (Advanced: 100.0 m³/unit)
    // ══════════════════════════════════════════════════════════════════

    public function test_p4_advanced_commodities_volume(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2867)
            ->andReturn(['name' => 'Broadcast Node']);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2867, 'amount' => 10],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // 10 * 100.0 = 1000 m³
        $this->assertEquals(10, $result['total_items']);
        $this->assertEquals(1000.0, $result['used_volume']);
        $this->assertEquals(10.0, $result['usage_percent']);
    }

    // ══════════════════════════════════════════════════════════════════
    // MIXED COMMODITY TESTS
    // ══════════════════════════════════════════════════════════════════

    public function test_mixed_pi_commodities_volume(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2267)->andReturn(['name' => 'Aqueous Liquids']);  // P0
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2400)->andReturn(['name' => 'Toxic Metals']);     // P1
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2312)->andReturn(['name' => 'Supertensile Plastics']); // P2

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2267, 'amount' => 10000],  // P0: 10000 * 0.01 = 100 m³
                ['type_id' => 2400, 'amount' => 1000],   // P1: 1000 * 0.19 = 190 m³
                ['type_id' => 2312, 'amount' => 100],    // P2: 100 * 1.5 = 150 m³
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // Total: 100 + 190 + 150 = 440 m³
        $this->assertEquals(11100, $result['total_items']);
        $this->assertEquals(440.0, $result['used_volume']);
        $this->assertEquals(4.4, $result['usage_percent']);
    }

    // ══════════════════════════════════════════════════════════════════
    // CAPACITY CONSTANTS TESTS
    // ══════════════════════════════════════════════════════════════════

    public function test_launchpad_capacity_is_10000(): void
    {
        $this->assertEquals(10000, PlanetaryService::LAUNCHPAD_CAPACITY);
    }

    public function test_storage_facility_capacity_is_12000(): void
    {
        $this->assertEquals(12000, PlanetaryService::STORAGE_CAPACITY);
    }

    public function test_default_volume_is_0_01(): void
    {
        $this->assertEquals(0.01, PlanetaryService::DEFAULT_VOLUME);
    }

    // ══════════════════════════════════════════════════════════════════
    // VOLUME RESOLUTION PRIORITY TESTS
    // ══════════════════════════════════════════════════════════════════

    public function test_volume_uses_database_first(): void
    {
        // Create a type in the database with a specific volume
        EveType::create([
            'type_id' => 99999,
            'name' => 'Test Item',
            'volume' => 5.5,
            'published' => true,
        ]);

        $this->esi->shouldReceive('getTypeInfo')
            ->with(99999)
            ->andReturn(['name' => 'Test Item', 'volume' => 1.0]); // ESI returns different value

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 99999, 'amount' => 100],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // Should use database volume (5.5), not ESI (1.0)
        // 100 * 5.5 = 550 m³
        $this->assertEquals(550.0, $result['used_volume']);
    }

    public function test_volume_uses_esi_when_database_missing(): void
    {
        // No database entry, ESI returns volume
        $this->esi->shouldReceive('getTypeInfo')
            ->with(88888)
            ->andReturn(['name' => 'ESI Item', 'volume' => 2.5]);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 88888, 'amount' => 100],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // Should use ESI volume (2.5)
        // 100 * 2.5 = 250 m³
        $this->assertEquals(250.0, $result['used_volume']);
    }

    public function test_volume_uses_pi_mapping_when_esi_has_no_volume(): void
    {
        // ESI returns type info but no volume field
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2400)
            ->andReturn(['name' => 'Toxic Metals']); // No volume field

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2400, 'amount' => 1000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // Should use PI_COMMODITY_VOLUMES mapping (0.19 for P1)
        // 1000 * 0.19 = 190 m³
        $this->assertEquals(190.0, $result['used_volume']);
    }

    public function test_volume_uses_default_when_all_else_fails(): void
    {
        // Unknown type, ESI returns no volume
        $this->esi->shouldReceive('getTypeInfo')
            ->with(11111)
            ->andReturn(['name' => 'Unknown Item']); // No volume field

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 11111, 'amount' => 1000],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // Should use DEFAULT_VOLUME (0.01)
        // 1000 * 0.01 = 10 m³
        $this->assertEquals(10.0, $result['used_volume']);
    }

    // ══════════════════════════════════════════════════════════════════
    // EDGE CASE TESTS
    // ══════════════════════════════════════════════════════════════════

    public function test_empty_contents_returns_zero_volume(): void
    {
        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        $this->assertEquals(0, $result['total_items']);
        $this->assertEquals(0.0, $result['used_volume']);
        $this->assertEquals(0, $result['usage_percent']);
    }

    public function test_missing_contents_key_returns_zero_volume(): void
    {
        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        $this->assertEquals(0, $result['total_items']);
        $this->assertEquals(0.0, $result['used_volume']);
    }

    public function test_usage_percent_capped_at_100(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2867)
            ->andReturn(['name' => 'Broadcast Node']);

        // 200 P4 items * 100 m³ = 20000 m³ (exceeds 10000 capacity)
        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2867, 'amount' => 200],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        $this->assertEquals(20000.0, $result['used_volume']);
        $this->assertEquals(100, $result['usage_percent']); // Capped at 100
    }

    public function test_item_count_matches_esi_amount(): void
    {
        $this->esi->shouldReceive('getTypeInfo')
            ->with(2400)
            ->andReturn(['name' => 'Toxic Metals']);

        $pin = [
            'pin_id' => 1,
            'type_id' => 2543,
            'contents' => [
                ['type_id' => 2400, 'amount' => 24940],
            ],
        ];

        $result = $this->service->computeStorageUsage($pin, PlanetaryService::LAUNCHPAD_CAPACITY);

        // Item count should exactly match ESI amount
        $this->assertEquals(24940, $result['total_items']);
        $this->assertEquals(24940, $result['items'][0]['amount']);
    }
}
