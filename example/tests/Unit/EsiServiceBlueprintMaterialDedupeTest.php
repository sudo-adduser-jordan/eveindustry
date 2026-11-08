<?php

namespace Tests\Unit;

use App\Models\BlueprintActivity;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EsiServiceBlueprintMaterialDedupeTest extends TestCase
{
    use RefreshDatabase;

    private EsiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->service = new EsiService;
    }

    public function test_duplicate_materials_are_merged_by_type_id(): void
    {
        BlueprintActivity::create([
            'blueprint_type_id' => 999001,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 3600,
            'materials' => [
                ['type_id' => 100, 'name' => 'Tungsten Carbide', 'quantity' => 22],
                ['type_id' => 100, 'name' => 'Tungsten Carbide', 'quantity' => 22],
                ['type_id' => 200, 'name' => 'Ferrogel', 'quantity' => 1],
                ['type_id' => 300, 'name' => 'Sylramic Fibers', 'quantity' => 9],
            ],
            'products' => [['type_id' => 999002, 'name' => 'Linear Shield Emitter', 'quantity' => 1]],
        ]);

        $result = $this->service->resolveBlueprintActivity(999001, 1);

        $this->assertNotNull($result);

        $materials = $result['materials'];

        // No duplicate type_ids
        $typeIds = array_column($materials, 'type_id');
        $this->assertSame($typeIds, array_values(array_unique($typeIds)));

        // Three distinct materials, with duplicates dropped (first occurrence kept)
        $this->assertCount(3, $materials);

        $byType = collect($materials)->keyBy('type_id');
        $this->assertSame(22, $byType[100]['quantity']);
        $this->assertSame(1, $byType[200]['quantity']);
        $this->assertSame(9, $byType[300]['quantity']);
    }
}
