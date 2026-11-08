<?php

namespace Tests\Feature\Calculators;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Golden snapshots of what the calculators return today for fixed seeded
 * inputs, taken before the Blade pages were ported to React. Each test pins
 * the whole /api/v1 body against a recorded file in ./golden and spells out
 * the headline numbers, so a change in the maths (or in a resource) fails
 * here with a readable diff. Re-record only for an intended change:
 * RECORD_CALCULATOR_FIXTURES=1 php artisan test tests/Feature/Calculators.
 */
class GoldenSnapshotTest extends TestCase
{
    use BrowsersAndPlannersFixtures;
    use CalculatorFixtures;
    use InventionFixtures;
    use RefreshDatabase;

    private const MANUFACTURING = '/api/v1/calculators/manufacturing';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-01 12:00:00');
    }

    public function test_manufacturing_with_job_fees(): void
    {
        $this->seedIndustryTree();

        $response = $this->actingAs($this->makeUser())->postJson(self::MANUFACTURING, self::feeRequest())->assertOk();

        // 10 runs of a 125,000 ISK product; SCC fee is 4% of 1,250,000.
        $response->assertJsonPath('data.product.total_output', 10)
            ->assertJsonPath('data.product.sell_price', 125000)
            ->assertJsonPath('data.product.total_revenue', 1250000)
            ->assertJsonPath('data.scc_fee', 50000)
            ->assertJsonPath('data.build_fee.has_data', true)
            ->assertJsonPath('data.build_fee.sci', 0.05)
            ->assertJsonPath('data.materials.0.component.is_buildable', true)
            ->assertJsonPath('meta.freshness.source', 'market_orders');

        $this->assertMatchesGolden('manufacturing_fees', $response);
    }

    public function test_manufacturing_reaction_system_only_charges_component_fees(): void
    {
        $this->seedIndustryTree();
        $request = self::feeRequest();
        unset($request['solar_system_id'], $request['tax_rate']);

        $response = $this->actingAs($this->makeUser())->postJson(self::MANUFACTURING, $request)->assertOk();

        $response->assertJsonPath('data.build_fee.has_data', true)
            ->assertJsonPath('data.build_fee.eiv', 0)
            ->assertJsonPath('data.build_fee.total', 0)
            ->assertJsonPath('data.build_fee.tax_rate', 0);
        $this->assertGreaterThan(0, $response->json('data.build_fee.components_fee'));

        $this->assertMatchesGolden('manufacturing_reaction_system_only', $response);
    }

    public function test_manufacturing_posted_materials_with_buyback(): void
    {
        $this->seedIndustryTree();

        $response = $this->actingAs($this->makeUser())->postJson(self::MANUFACTURING, [
            'blueprint_type_id' => 77002,
            'product_type_id' => 77001,
            'runs' => 3,
            'me_level' => 10,
            'te_level' => 20,
            'industry_skill' => 0,
            'advanced_industry_skill' => 0,
            'materials' => [
                ['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 2000],
                ['type_id' => 77003, 'name' => 'Test Component', 'quantity' => 2],
            ],
            'base_time' => 1000,
            'portion_size' => 2,
            'buyback_percentage' => 90,
        ])->assertOk();

        $response->assertJsonPath('data.build_fee.has_data', false)
            ->assertJsonPath('data.product.total_output', 6)
            ->assertJsonPath('data.time.base_time_seconds', 1000);

        $this->assertMatchesGolden('manufacturing_posted_materials_buyback', $response);
    }

    public function test_manufacturing_second_item_of_a_multi_item_queue(): void
    {
        $this->seedIndustryTree();

        $response = $this->actingAs($this->makeUser())->postJson(self::MANUFACTURING, [
            'blueprint_type_id' => 77004,
            'product_type_id' => 77003,
            'runs' => 20,
            'me_level' => 10,
            'te_level' => 20,
            'industry_skill' => 5,
            'advanced_industry_skill' => 5,
        ])->assertOk();

        $response->assertJsonPath('data.product.total_output', 20)
            ->assertJsonPath('data.product.total_revenue', 30000);

        $this->assertMatchesGolden('manufacturing_second_item', $response);
    }

    public function test_queue_summary_over_several_items(): void
    {
        $response = $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/manufacturing/queue-summary', ['items' => [
            ['revenue' => 1000000, 'input_cost' => 600000, 'fees' => 55000.5],
            ['revenue' => 0, 'input_cost' => 0, 'fees' => 0],
            ['revenue' => '250000.75', 'input_cost' => '300000', 'fees' => '12000'],
        ]])->assertOk();

        $response->assertJsonPath('data.items.1.profit_margin', 0)
            ->assertJsonPath('data.items.0.profit', 344999.5);

        $this->assertMatchesGolden('queue_summary', $response);
    }

    public function test_reactions_calculate(): void
    {
        $this->seedIndustryTree();

        $response = $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/reactions', [
            'formula_type_id' => 77006,
            'product_type_id' => 77005,
            'runs' => 15,
            'reactions_skill' => 4,
            'rig_bonus' => 2.4,
            'structure_te_bonus' => 20,
        ])->assertOk();

        // 15 runs x 200 composites at 300 ISK.
        $response->assertJsonPath('data.product.total_output', 3000)
            ->assertJsonPath('data.product.total_revenue', 900000)
            ->assertJsonPath('data.time.base_time_seconds', 10800);

        $this->assertMatchesGolden('reactions_calculate', $response);
    }

    public function test_reaction_planner_calculate(): void
    {
        $this->seedIndustryTree();

        $response = $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/reaction-planner', [
            'entries' => [
                ['formula_type_id' => 77010, 'formula_name' => 'Advanced', 'slots' => 2, 'runs_per_slot' => 30],
                ['formula_type_id' => 77006, 'slots' => 1, 'runs_per_slot' => 5],
            ],
            'structure_type' => 'tatara',
            'rig_tier' => 't1',
            'security_space' => 'nullsec',
            'facility_tax' => 2,
            'system_cost_index' => 3.5,
        ])->assertOk();

        $response->assertJsonPath('data.effective_rig_bonus', 2.2)
            ->assertJsonPath('data.slots.total', 4)
            ->assertJsonPath('data.fees.system_cost_index', 3.5);

        $this->assertMatchesGolden('reaction_planner_calculate', $response);
    }

    public function test_reaction_planner_skips_unknown_formulas(): void
    {
        $this->fakeEsi();

        $response = $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/reaction-planner', [
            'entries' => [['formula_type_id' => 1, 'slots' => 1, 'runs_per_slot' => 1]],
        ])->assertOk();

        $this->assertMatchesGolden('reaction_planner_empty', $response);
    }

    public function test_reaction_browser_calculate(): void
    {
        $this->seedIndustryTree();

        $response = $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/reaction-browser', [
            'runs' => 20,
            'rig_tier' => 't2',
            'security_space' => 'lowsec',
            'facility_tax' => 1.5,
            'system_cost_index' => 6,
        ])->assertOk();

        $response->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.effective_rig_bonus', 2.4);

        $this->assertMatchesGolden('reaction_browser_calculate', $response);
    }

    public function test_manufacturing_browser_calculate(): void
    {
        $this->seedBrowser();

        $response = $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/manufacturing-browser', self::browserCalculateRequest())->assertOk();

        $response->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.effective_structure_me_bonus', 3.8);

        $this->assertMatchesGolden('manufacturing_browser_calculate', $response);
    }

    public function test_manufacturing_browser_browse(): void
    {
        $this->seedBrowser();

        $response = $this->actingAs($this->makeUser())->getJson('/api/v1/calculators/manufacturing-browser?'.http_build_query(self::browseRequest()))->assertOk();

        $response->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.effective_structure_me_bonus', 5.04);

        $this->assertMatchesGolden('manufacturing_browser_browse', $response);
    }

    public function test_invention_full(): void
    {
        $this->seedInvention();

        $response = $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/invention', self::fullRequest())->assertOk();

        $response->assertJsonPath('data.t1_item.name', 'Rifter');
        // TE: 3% + 20 x 2.1 (T1 rig x nullsec)
        $this->assertEquals(45, $response->json('data.structure_bonuses.te'));
        // Raitaru 1% role bonus combined with T2 rig 2.4 x 2.1 nullsec
        $this->assertEqualsWithDelta((1 - 0.99 * (1 - 0.0504)) * 100, $response->json('data.structure_bonuses.me'), 0.01);

        $this->assertMatchesGolden('invention_full', $response);
    }

    public function test_invention_defaults_without_structure_or_decryptor(): void
    {
        $this->seedInvention();

        $response = $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/invention', [
            't2_type_id' => 11371,
            'encryption_skill' => 0,
            'science1_skill' => 0,
            'science2_skill' => 0,
            'invention_runs' => 1,
            'base_probability' => 0.5,
            'base_runs' => 10,
        ])->assertOk();

        $response->assertJsonPath('data.invention.expected_attempts', 2)
            ->assertJsonPath('data.structure_bonuses.me', 0);

        $this->assertMatchesGolden('invention_defaults', $response);
    }
}
