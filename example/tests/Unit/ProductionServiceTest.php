<?php

namespace Tests\Unit;

use App\Models\EveType;
use App\Services\EsiService;
use App\Services\ProductionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ProductionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $esi = Mockery::mock(EsiService::class);
        $this->service = new ProductionService($esi);
    }

    // ── aggregateMaterials ──────────────────────────────────────────

    public function test_aggregate_materials_sums_quantities_by_type(): void
    {
        $method = new \ReflectionMethod(ProductionService::class, 'aggregateMaterials');

        $assets = [
            ['type_id' => 34, 'quantity' => 100],
            ['type_id' => 35, 'quantity' => 50],
            ['type_id' => 34, 'quantity' => 200],
        ];

        $result = $method->invoke($this->service, $assets);

        $this->assertEquals(300, $result[34]);
        $this->assertEquals(50, $result[35]);
        $this->assertCount(2, $result);
    }

    public function test_aggregate_materials_skips_zero_quantity(): void
    {
        $method = new \ReflectionMethod(ProductionService::class, 'aggregateMaterials');

        $assets = [
            ['type_id' => 34, 'quantity' => 0],
            ['type_id' => 35, 'quantity' => 10],
        ];

        $result = $method->invoke($this->service, $assets);

        $this->assertArrayNotHasKey(34, $result);
        $this->assertEquals(10, $result[35]);
    }

    public function test_aggregate_materials_empty_assets(): void
    {
        $method = new \ReflectionMethod(ProductionService::class, 'aggregateMaterials');

        $result = $method->invoke($this->service, []);

        $this->assertEmpty($result);
    }

    // ── resolveAvailableMaterials ───────────────────────────────────

    public function test_resolve_available_materials_returns_named_entries(): void
    {
        EveType::create(['type_id' => 34, 'name' => 'Tritanium']);
        EveType::create(['type_id' => 35, 'name' => 'Pyerite']);

        $method = new \ReflectionMethod(ProductionService::class, 'resolveAvailableMaterials');

        $materials = [34 => 1000, 35 => 500];
        $result = $method->invoke($this->service, $materials);

        $this->assertCount(2, $result);

        // Sorted alphabetically: Pyerite before Tritanium
        $this->assertEquals('Pyerite', $result[0]['name']);
        $this->assertEquals(500, $result[0]['quantity']);
        $this->assertEquals(35, $result[0]['type_id']);

        $this->assertEquals('Tritanium', $result[1]['name']);
        $this->assertEquals(1000, $result[1]['quantity']);
        $this->assertEquals(34, $result[1]['type_id']);
    }

    public function test_resolve_available_materials_falls_back_for_unknown_types(): void
    {
        $method = new \ReflectionMethod(ProductionService::class, 'resolveAvailableMaterials');

        $materials = [99999 => 42];
        $result = $method->invoke($this->service, $materials);

        $this->assertCount(1, $result);
        $this->assertEquals('Type #99999', $result[0]['name']);
        $this->assertEquals(42, $result[0]['quantity']);
    }

    public function test_resolve_available_materials_empty_input(): void
    {
        $method = new \ReflectionMethod(ProductionService::class, 'resolveAvailableMaterials');

        $result = $method->invoke($this->service, []);

        $this->assertEmpty($result);
    }
}
