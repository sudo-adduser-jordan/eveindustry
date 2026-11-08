<?php

namespace Tests\Feature;

use App\Models\MarketOrder;
use App\Models\User;
use App\Services\InventionPlannerService;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class InventionPlannerCalculateTest extends TestCase
{
    use RefreshDatabase;

    private function sellOrder(int $typeId, float $price): void
    {
        static $orderId = 9100000;

        MarketOrder::create([
            'order_id' => ++$orderId,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'type_id' => $typeId,
            'is_buy_order' => false,
            'price' => $price,
            'volume_remain' => 1000,
            'volume_total' => 1000,
            'issued' => now(),
        ]);
    }

    public function test_datacore_and_decryptor_shopping_list_comes_from_the_server(): void
    {
        $user = User::create(['name' => 'Test', 'email' => 'inv@example.com', 'is_active' => true]);

        // Minimal invention chain: T1 Rifter → T2 Wolf, two datacores
        // Proxy the real service so everything except these two methods runs as normal
        $planner = Mockery::mock(app(InventionPlannerService::class));
        $planner->shouldReceive('resolveInventionChain')->andReturn([
            't2_item_type_id' => 11371,
            't2_blueprint_type_id' => 11372,
            't2_manufacturing' => [
                'time' => 3600,
                'materials' => [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100]],
                'products' => [['type_id' => 11371, 'name' => 'Wolf', 'quantity' => 1]],
            ],
            't1_item_type_id' => 587,
            't1_item_name' => 'Rifter',
            't1_blueprint_type_id' => 691,
            't1_manufacturing' => ['materials' => [], 'products' => []],
            'datacores' => [
                ['type_id' => 20171, 'name' => 'Datacore - Mechanical Engineering', 'quantity' => 2],
                ['type_id' => 20424, 'name' => 'Datacore - Minmatar Starship Engineering', 'quantity' => 3],
            ],
            'invention_time' => 1200,
            'base_probability' => 0.34,
            'base_runs' => 1,
            'science_skill_ids' => [],
            'encryption_skill_id' => null,
            'encryption_skill_name' => null,
            'science1_skill_id' => null,
            'science1_skill_name' => null,
            'science2_skill_id' => null,
            'science2_skill_name' => null,
        ]);
        $planner->shouldReceive('resolveComponentBuildData')->andReturn([]);
        $this->app->instance(InventionPlannerService::class, $planner);

        $this->sellOrder(20171, 100000.0);
        $this->sellOrder(20424, 50000.0);
        $this->sellOrder(34201, 1000000.0); // Accelerant Decryptor
        $this->sellOrder(34, 5.0);
        $this->sellOrder(11371, 30000000.0);

        $response = $this->actingAs($user)->postJson('/api/v1/calculators/invention', [
            't2_type_id' => 11371,
            'decryptor_key' => 'accelerant',
            'encryption_skill' => 0,
            'science1_skill' => 0,
            'science2_skill' => 0,
            'invention_runs' => 3,
        ]);

        $response->assertOk();
        $data = $response->json('data');

        // Probability 0.34 × 1.2 (accelerant) = 0.408 → ceil(1 / 0.408) = 3 attempts per run; 3 runs = 9
        $this->assertEquals(3, $data['invention']['expected_attempts']);
        $this->assertEquals(9, $data['invention']['total_attempts']);

        $datacores = collect($data['invention']['datacores'])->keyBy('type_id');
        // Old JS: Math.ceil(quantity × attempts) and unit_price × that quantity
        $this->assertEquals(18, $datacores[20171]['total_quantity']);
        $this->assertEqualsWithDelta(1800000.0, $datacores[20171]['total_quantity_cost'], 0.01);
        $this->assertEquals(27, $datacores[20424]['total_quantity']);
        $this->assertEqualsWithDelta(1350000.0, $datacores[20424]['total_quantity_cost'], 0.01);
        // Existing per-attempt keys are unchanged
        $this->assertEqualsWithDelta(200000.0, $datacores[20171]['total_cost'], 0.01);

        $this->assertEquals(9, $data['invention']['decryptor']['total_quantity']);
        $this->assertEqualsWithDelta(9000000.0, $data['invention']['decryptor']['total_cost'], 0.01);
    }
}
