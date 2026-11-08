<?php

namespace Tests\Feature\ApiV1\Calculators;

use App\Models\BlueprintActivity;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Calculators\BrowsersAndPlannersFixtures;
use Tests\Feature\Calculators\CalculatorFixtures;
use Tests\TestCase;

/**
 * Shapes, errors and auth of the browser, stock and factory planner
 * endpoints; the browsers' numbers are pinned by GoldenSnapshotTest.
 */
class BrowsersAndStockApiTest extends TestCase
{
    use BrowsersAndPlannersFixtures;
    use CalculatorFixtures;
    use RefreshDatabase;

    private const FACTORY_URL = '/api/planetary/factory-planner/calculate';

    public function test_manufacturing_browser_calculate(): void
    {
        $this->seedBrowser();
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/v1/calculators/manufacturing-browser', self::browserCalculateRequest());

        $response->assertOk()
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 50, 'total' => 2, 'last_page' => 1])
            ->assertJsonPath('meta.effective_structure_me_bonus', 3.8)
            ->assertJsonStructure(['meta' => ['freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale']]])
            ->assertJsonPath('data.0.base', null);

        $this->actingAs($user)->postJson('/api/v1/calculators/manufacturing-browser', ['runs' => 1])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['market_group_id']);

        $this->asGuest()->postJson('/api/v1/calculators/manufacturing-browser', self::browserCalculateRequest())
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_manufacturing_browser_browse(): void
    {
        $this->seedBrowser();
        $user = $this->makeUser();
        $query = http_build_query(self::browseRequest());

        $response = $this->actingAs($user)->getJson('/api/v1/calculators/manufacturing-browser?'.$query.'&per_page=1');

        $response->assertOk()
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 1, 'total' => 2, 'last_page' => 2])
            ->assertJsonPath('meta.effective_structure_me_bonus', 5.04)
            ->assertJsonCount(1, 'data');

        // The stored 1-run, no-fee figures of the precomputed row.
        $this->assertSame(
            ['input_cost' => 1000, 'output_value' => 2000, 'profit' => 920, 'profit_margin' => 92, 'profit_positive' => true],
            $response->json('data.0.base')
        );

        $this->actingAs($user)->getJson('/api/v1/calculators/manufacturing-browser?runs=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['market_group_id', 'runs']);
        $this->asGuest()->getJson('/api/v1/calculators/manufacturing-browser?market_group_id=100')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_factory_planner(): void
    {
        $this->mockFactoryPlanner();
        $user = $this->makeUser();
        // The old route's recorded response (the alias is gone); v1 returns the same data.
        $old = json_decode((string) file_get_contents(__DIR__.'/../../LegacyApi/Calculators/fixtures/factory_planner.json'), true);

        $this->actingAs($user)->getJson('/api/v1/calculators/factory-planner?tier=p1_to_p2&region_id=10000043&tax_rate=12.5')
            ->assertOk()
            ->assertJsonPath('data', $old)
            ->assertJsonStructure(['meta' => ['freshness' => ['prices_as_of', 'source', 'stale']]]);

        $this->actingAs($user)->getJson('/api/v1/calculators/factory-planner')
            ->assertOk()
            ->assertJsonPath('data.tier', 'p2_to_p3')
            ->assertJsonPath('data.results.0.output_name', 'P3 p2_to_p3 10000002');

        $this->actingAs($user)->getJson('/api/v1/calculators/factory-planner?tier=nope&tax_rate=150')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['tier', 'tax_rate']);

        $this->asGuest()->getJson('/api/v1/calculators/factory-planner')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_asset_stock(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, 90000001, ['esi-assets.read_assets.v1']);
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterAssets')->andReturn([
            ['type_id' => 34, 'quantity' => 5000],
            ['type_id' => 34, 'quantity' => 2500],
            ['type_id' => 35, 'quantity' => 10],
        ]);
        $this->app->instance(EsiService::class, $esi);

        $expected = [['type_id' => 34, 'quantity' => 7500], ['type_id' => 35, 'quantity' => 10], ['type_id' => 36, 'quantity' => 0]];

        foreach (['manufacturing', 'reaction-planner'] as $calculator) {
            $url = "/api/v1/calculators/{$calculator}/stock";
            $this->actingAs($user)->postJson($url, ['type_ids' => [34, 35, 36, 34]])
                ->assertOk()
                ->assertExactJson(['data' => $expected, 'meta' => []]);

            $this->actingAs($user)->postJson($url, ['type_ids' => []])
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed');

            $this->asGuest()->postJson($url, ['type_ids' => [34]])
                ->assertStatus(401)
                ->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_formula_stock(): void
    {
        $this->blueprint(77006, BlueprintActivity::ACTIVITY_REACTION, [], [['type_id' => 77005, 'quantity' => 1]]);
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterBlueprints')->andReturn([['type_id' => 77006, 'quantity' => 2]]);
        $esi->shouldReceive('getCharacterIndustryJobs')->andReturn([]);
        $this->app->instance(EsiService::class, $esi);

        $url = '/api/v1/calculators/reaction-planner/formula-stock';
        $noScope = $this->makeUser('noscope@example.com');
        $this->makeCharacter($noScope, 90000009, []);
        $this->actingAs($noScope)->postJson($url, ['formula_type_ids' => [77006]])
            ->assertOk()
            ->assertExactJson(['data' => ['has_blueprint_scope' => false, 'formulas' => []], 'meta' => []]);

        $user = $this->makeUser();
        $this->makeCharacter($user, 90000001, ['esi-characters.read_blueprints.v1']);
        $this->actingAs($user)->postJson($url, ['formula_type_ids' => [77006, 77010]])
            ->assertOk()
            ->assertExactJson(['data' => [
                'has_blueprint_scope' => true,
                'formulas' => [['type_id' => 77006, 'quantity' => 2], ['type_id' => 77010, 'quantity' => 0]],
            ], 'meta' => []]);

        $this->actingAs($user)->postJson($url, [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['formula_type_ids']);
        $this->asGuest()->postJson($url, ['formula_type_ids' => [1]])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }
}
