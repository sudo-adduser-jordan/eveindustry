<?php

namespace Tests\Feature;

use App\Models\BlueprintActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ManufacturingCalculateBuybackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    /**
     * Seed a minimal blueprint chain:
     *   Product (type 77001) ← Blueprint (type 77002)
     *     └ Component (type 77003) ← Blueprint (type 77004)
     *          └ Tritanium (type 34) — base raw material
     *
     * Also sets up Http::fake() so ESI calls in fetchAndStoreRegionalPrices
     * return real prices (preventing them from wiping seeded data with nulls).
     * Prices per type: Tritanium=10, Component=1000, Product=10000, all others=5.
     */
    private function seedBlueprintChainWithFakeEsi(): void
    {
        // Component blueprint: Tritanium → Component
        BlueprintActivity::create([
            'blueprint_type_id' => 77004,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 300,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100],
            ],
            'products' => [
                ['type_id' => 77003, 'name' => 'Test Component', 'quantity' => 1],
            ],
        ]);

        // Top-level blueprint: Component → Product
        BlueprintActivity::create([
            'blueprint_type_id' => 77002,
            'activity_id' => BlueprintActivity::ACTIVITY_MANUFACTURING,
            'time' => 600,
            'materials' => [
                ['type_id' => 77003, 'name' => 'Test Component', 'quantity' => 5],
            ],
            'products' => [
                ['type_id' => 77001, 'name' => 'Test Product', 'quantity' => 1],
            ],
        ]);

        // Fake ESI market orders so fetchAndStoreRegionalPrices writes real prices.
        // Different prices per type_id let us verify buy and build costs independently.
        $prices = [34 => 10.0, 77003 => 1000.0, 77001 => 10000.0];

        Http::fake(function ($request) use ($prices) {
            parse_str((string) $request->toPsrRequest()->getUri()->getQuery(), $params);
            $typeId = (int) ($params['type_id'] ?? 0);
            $price = $prices[$typeId] ?? 5.0;

            return Http::response(json_encode([
                ['price' => $price, 'volume_remain' => 1000, 'is_buy_order' => false],
            ]));
        });
    }

    private function calculateRequest(array $extra = []): array
    {
        return array_merge([
            'blueprint_type_id' => 77002,
            'product_type_id' => 77001,
            'runs' => 1,
            'me_level' => 0,
            'te_level' => 0,
            'industry_skill' => 0,
            'advanced_industry_skill' => 0,
        ], $extra);
    }

    public function test_buyback_applies_to_top_level_materials(): void
    {
        $this->seedBlueprintChainWithFakeEsi();

        $noBuyback = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/manufacturing', $this->calculateRequest());
        $noBuyback->assertOk();

        $buyback90 = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/manufacturing', $this->calculateRequest(['buyback_percentage' => 90.0]));
        $buyback90->assertOk();

        $noBuybackCost = $noBuyback->json('data.scenarios.full_buy.material_cost');
        $buyback90Cost = $buyback90->json('data.scenarios.full_buy.material_cost');

        $this->assertGreaterThan(0, $noBuybackCost, 'Expected non-zero buy cost without buyback');

        // 90% buyback reduces the top-level buy cost by 10%
        $this->assertEqualsWithDelta($noBuybackCost * 0.9, $buyback90Cost, 0.01);
    }

    public function test_buyback_applies_to_sub_materials_in_full_build(): void
    {
        $this->seedBlueprintChainWithFakeEsi();

        $noBuyback = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/manufacturing', $this->calculateRequest());
        $noBuyback->assertOk();

        $buyback90 = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/manufacturing', $this->calculateRequest(['buyback_percentage' => 90.0]));
        $buyback90->assertOk();

        $noBuybackBuildCost = $noBuyback->json('data.scenarios.full_build.material_cost');
        $buyback90BuildCost = $buyback90->json('data.scenarios.full_build.material_cost');

        $this->assertGreaterThan(0, $noBuybackBuildCost, 'Expected non-zero build cost without buyback');

        // Sub-material prices are also discounted by the buyback percentage
        $this->assertLessThan($noBuybackBuildCost, $buyback90BuildCost);

        // 90% buyback reduces the full-build cost by 10%
        $this->assertEqualsWithDelta($noBuybackBuildCost * 0.9, $buyback90BuildCost, 0.01);
    }

    public function test_totals_include_scc_fee_and_margins_are_on_input_cost_without_job_fees(): void
    {
        $this->seedBlueprintChainWithFakeEsi();

        $data = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/manufacturing', $this->calculateRequest())
            ->assertOk()
            ->json('data');
        $buy = $data['scenarios']['full_buy'];
        $build = $data['scenarios']['full_build'];

        // No structure system, so the only fee is the SCC fee
        $this->assertEqualsWithDelta($data['scc_fee'], $buy['total_fees'], 0.01);
        $this->assertEqualsWithDelta($data['scc_fee'], $build['total_fees'], 0.01);
        $this->assertEqualsWithDelta($buy['material_cost'] + $data['scc_fee'], $buy['total_cost'], 0.01);
        $this->assertEqualsWithDelta($build['material_cost'] + $data['scc_fee'], $build['total_cost'], 0.01);

        // With no job fee the with-fee figures equal the plain ones,
        // and margin is profit ÷ input cost
        $this->assertEqualsWithDelta($buy['profit'], $buy['profit_after_fees'], 0.01);
        $this->assertEquals($buy['margin'], $buy['margin_after_fees']);
        $this->assertEquals($build['margin'], $build['margin_after_fees']);
        $this->assertEquals(
            round($buy['profit'] / $buy['material_cost'] * 100, 1),
            $buy['margin_after_fees']
        );
    }

    public function test_100_percent_buyback_does_not_alter_costs(): void
    {
        $this->seedBlueprintChainWithFakeEsi();

        $noBuyback = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/manufacturing', $this->calculateRequest());

        $buyback100 = $this->actingAs($this->user)
            ->postJson('/api/v1/calculators/manufacturing', $this->calculateRequest(['buyback_percentage' => 100.0]));

        // 100% buyback = no change
        $this->assertEquals($noBuyback->json('data.scenarios.full_buy.material_cost'), $buyback100->json('data.scenarios.full_buy.material_cost'));
        $this->assertEquals($noBuyback->json('data.scenarios.full_build.material_cost'), $buyback100->json('data.scenarios.full_build.material_cost'));
    }
}
