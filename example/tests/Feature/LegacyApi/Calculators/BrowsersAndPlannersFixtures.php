<?php

namespace Tests\Feature\LegacyApi\Calculators;

use App\Models\ManufacturingCost;
use App\Models\MarketGroup;
use App\Services\FactoryPlanetPlannerService;
use App\Services\MarketService;
use Mockery;

/**
 * Seeding and request builders shared by the old-route characterisation test and the /api/v1 test (no test methods, so neither suite re-runs the other).
 */
trait BrowsersAndPlannersFixtures
{
    public const BROWSER_CALCULATE_URL = '/api/calculators/manufacturing-browser/calculate';

    public const BROWSER_BROWSE_URL = '/api/calculators/manufacturing-browser/browse';

    public const QUEUE_URL = '/api/calculators/manufacturing/queue-summary';

    protected function seedBrowser(): void
    {
        $this->seedIndustryTree();
        MarketGroup::create(['market_group_id' => 100, 'name' => 'Test Group', 'parent_group_id' => null, 'has_types' => true]);

        foreach ([[77001, 77002, 'Test Product', 1000, 2000, 920, 92.0], [77003, 77004, 'Test Component', 500, 450, -68, -13.6]] as [$product, $bp, $name, $in, $out, $profit, $margin]) {
            ManufacturingCost::create([
                'product_type_id' => $product,
                'blueprint_type_id' => $bp,
                'region_id' => MarketService::DEFAULT_REGION_ID,
                'me_level' => 0,
                'product_name' => $name,
                'blueprint_name' => $name.' Blueprint',
                'portion_size' => 1,
                'input_cost' => $in,
                'output_value' => $out,
                'base_profit' => $profit,
                'profit_margin' => $margin,
                'sell_score' => 7,
                'sell_score_avg_daily_volume' => 50.0,
                'sell_score_avg_order_count' => 10.0,
                'sell_score_active_days' => 28,
                'sell_score_total_days' => 30,
            ]);
        }
    }

    public static function browserCalculateRequest(): array
    {
        return [
            'runs' => 5,
            'me_level' => 10,
            'rig_tier' => 't1',
            'security_space' => 'lowsec',
            'facility_tax' => 10,
            'system_cost_index' => 5,
            'market_group_id' => 100,
        ];
    }

    public static function browseRequest(): array
    {
        return [
            'market_group_id' => 100,
            'runs' => 10,
            'rig_tier' => 't2',
            'security_space' => 'nullsec',
            'facility_tax' => 10,
            'system_cost_index' => 5,
        ];
    }

    protected function mockFactoryPlanner(): void
    {
        $planner = Mockery::mock(FactoryPlanetPlannerService::class)->makePartial();
        $planner->shouldReceive('calculateProfitability')->andReturnUsing(fn (string $tier, int $regionId) => [[
            'output_name' => "P3 {$tier} {$regionId}",
            'daily_input_cost_planet' => 1000.0,
            'daily_output_sell_planet' => 3000.0,
            'daily_profit_sell_planet' => 2000.0,
        ]]);
        $this->app->instance(FactoryPlanetPlannerService::class, $planner);
    }
}
