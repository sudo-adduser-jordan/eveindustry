<?php

namespace Tests\Feature;

use App\Models\EveType;
use App\Models\ManufacturingCost;
use App\Models\MarketGroup;
use App\Models\User;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManufacturingBrowserBrowseTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Test User', 'email' => 'test@example.com', 'is_active' => true]);

        MarketGroup::create(['market_group_id' => 100, 'name' => 'Frigates', 'parent_group_id' => 2, 'has_types' => true]);
        EveType::create(['type_id' => 587, 'name' => 'Rifter', 'market_group_id' => 100, 'published' => true]);
        ManufacturingCost::create([
            'product_type_id' => 587,
            'blueprint_type_id' => 687,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'me_level' => 0,
            'product_name' => 'Rifter',
            'blueprint_name' => 'Rifter Blueprint',
            'portion_size' => 1,
            'input_cost' => 1000,
            'output_value' => 2000,
            'base_profit' => 920,
            'profit_margin' => 92.0,
            'sell_score' => 7,
            'sell_score_avg_daily_volume' => 50.0,
            'sell_score_avg_order_count' => 10.0,
            'sell_score_active_days' => 28,
            'sell_score_total_days' => 30,
        ]);
    }

    public function test_browse_returns_server_computed_fees_profit_and_margin(): void
    {
        // T1 rig in lowsec: 2.0 × 1.9 = 3.8% structure ME bonus
        $response = $this->actingAs($this->user)->getJson('/api/v1/calculators/manufacturing-browser?'.http_build_query([
            'market_group_id' => 100,
            'runs' => 10,
            'rig_tier' => 't1',
            'security_space' => 'lowsec',
            'facility_tax' => 10,
            'system_cost_index' => 5,
        ]));

        $response->assertOk()
            ->assertJsonPath('meta.effective_structure_me_bonus', 3.8);

        $item = $response->json('data.0');

        // Input = 1,000 × 10 × 0.962 = 9,620; output = 20,000
        // Job cost = 9,620 × 5% × 1.10 = 529.10; SCC = 800
        // Profit = 20,000 - 9,620 - 529.10 - 800 = 9,050.90; margin = 94.1%
        $this->assertEqualsWithDelta(9620.0, $item['total_input_cost'], 0.001);
        $this->assertEqualsWithDelta(529.1, $item['total_job_cost'], 0.001);
        $this->assertEqualsWithDelta(800.0, $item['scc_fee'], 0.001);
        $this->assertEqualsWithDelta(9050.9, $item['profit'], 0.001);
        $this->assertEquals(94.1, $item['profit_margin']);
        $this->assertTrue($item['profit_positive']);

        // The stored 1-run, no-fee figures are still there
        $this->assertEquals(1000, $item['base']['input_cost']);
        $this->assertEquals(2000, $item['base']['output_value']);
        $this->assertEquals(920, $item['base']['profit']);
        $this->assertEquals(92.0, $item['base']['profit_margin']);
    }

    public function test_browse_without_settings_uses_one_run_and_no_fees(): void
    {
        $item = $this->actingAs($this->user)
            ->getJson('/api/v1/calculators/manufacturing-browser?market_group_id=100')
            ->assertOk()
            ->json('data.0');

        $this->assertEquals(1, $item['total_output']);
        $this->assertEqualsWithDelta(0.0, $item['total_job_cost'], 0.001);
        $this->assertEqualsWithDelta(920.0, $item['profit'], 0.001);
        $this->assertEquals(92.0, $item['profit_margin']);
    }

    public function test_browse_rejects_invalid_settings(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/calculators/manufacturing-browser?'.http_build_query(['market_group_id' => 100, 'rig_tier' => 'gold', 'runs' => 0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rig_tier', 'runs']);
    }
}
