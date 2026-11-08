<?php

namespace Tests\Unit;

use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class EsiServiceReactionProductPartitionTest extends TestCase
{
    use RefreshDatabase;

    private EsiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->service = new EsiService;
    }

    public function test_reaction_output_in_activity_materials_is_classified_as_product(): void
    {
        // Fuzzwork lists the reaction output inside activityMaterials, tagged
        // with maketype pointing back at this blueprint.
        Cache::put('sde.blueprint.57492', [
            'requestedid' => 57492,
            'blueprintDetails' => ['times' => ['11' => 10800]],
            'activityMaterials' => [
                '11' => [
                    ['typeid' => 16659, 'name' => 'Carbon Polymers', 'quantity' => 200, 'maketype' => -1],
                    ['typeid' => 16661, 'name' => 'Sulfuric Acid', 'quantity' => 200, 'maketype' => -1],
                    ['typeid' => 57454, 'name' => 'Oxy-Organic Solvents', 'quantity' => 1, 'maketype' => 57492],
                ],
            ],
        ], 600);

        $result = $this->service->resolveBlueprintActivity(57492, 11);

        $this->assertNotNull($result);

        // The output is a product, not an input material.
        $materialTypeIds = array_column($result['materials'], 'type_id');
        $this->assertNotContains(57454, $materialTypeIds);
        $this->assertEqualsCanonicalizing([16659, 16661], $materialTypeIds);

        $this->assertCount(1, $result['products']);
        $this->assertSame(57454, $result['products'][0]['type_id']);
        $this->assertSame('Oxy-Organic Solvents', $result['products'][0]['name']);
        $this->assertSame(1, $result['products'][0]['quantity']);
    }

    public function test_manufacturing_product_falls_back_to_blueprint_details(): void
    {
        // Manufacturing output is not listed in activityMaterials, so it must
        // come from blueprintDetails.productTypeID.
        Cache::put('sde.blueprint.681', [
            'requestedid' => 681,
            'blueprintDetails' => [
                'productTypeID' => 587,
                'productTypeName' => 'Rifter',
                'productQuantity' => 1,
                'times' => ['1' => 600],
            ],
            'activityMaterials' => [
                '1' => [
                    ['typeid' => 34, 'name' => 'Tritanium', 'quantity' => 24000, 'maketype' => -1],
                    ['typeid' => 35, 'name' => 'Pyerite', 'quantity' => 4500, 'maketype' => -1],
                ],
            ],
        ], 600);

        $result = $this->service->resolveBlueprintActivity(681, 1);

        $this->assertNotNull($result);
        $this->assertEqualsCanonicalizing([34, 35], array_column($result['materials'], 'type_id'));
        $this->assertCount(1, $result['products']);
        $this->assertSame(587, $result['products'][0]['type_id']);
        $this->assertSame('Rifter', $result['products'][0]['name']);
        $this->assertSame(1, $result['products'][0]['quantity']);
    }
}
