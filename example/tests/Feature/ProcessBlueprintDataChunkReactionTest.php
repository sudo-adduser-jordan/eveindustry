<?php

namespace Tests\Feature;

use App\Jobs\ProcessBlueprintDataChunk;
use App\Models\BlueprintActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessBlueprintDataChunkReactionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reaction_output_is_stored_as_product_not_material(): void
    {
        Http::fake([
            'www.fuzzwork.co.uk/blueprint/api/blueprint.php*' => Http::response([
                'requestedid' => 57492,
                'blueprintDetails' => ['times' => ['11' => 10800]],
                'activityMaterials' => [
                    '11' => [
                        ['typeid' => 16659, 'name' => 'Carbon Polymers', 'quantity' => 200, 'maketype' => -1],
                        ['typeid' => 16661, 'name' => 'Sulfuric Acid', 'quantity' => 200, 'maketype' => -1],
                        ['typeid' => 57454, 'name' => 'Oxy-Organic Solvents', 'quantity' => 1, 'maketype' => 57492],
                    ],
                ],
            ], 200),
        ]);

        ProcessBlueprintDataChunk::dispatchSync([57492]);

        $activity = BlueprintActivity::where('blueprint_type_id', 57492)
            ->where('activity_id', 11)
            ->first();

        $this->assertNotNull($activity);

        $materialTypeIds = array_column($activity->materials, 'type_id');
        $this->assertNotContains(57454, $materialTypeIds);
        $this->assertEqualsCanonicalizing([16659, 16661], $materialTypeIds);

        $this->assertCount(1, $activity->products);
        $this->assertSame(57454, $activity->products[0]['type_id']);
        $this->assertSame('Oxy-Organic Solvents', $activity->products[0]['name']);
        $this->assertSame(1, $activity->products[0]['quantity']);
    }
}
