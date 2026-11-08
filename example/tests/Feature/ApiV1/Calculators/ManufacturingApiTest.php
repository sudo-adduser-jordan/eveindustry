<?php

namespace Tests\Feature\ApiV1\Calculators;

use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Feature\Calculators\CalculatorFixtures;
use Tests\TestCase;

/**
 * Shapes, errors and auth of the calculator endpoints; the numbers they
 * return are pinned by Tests\Feature\Calculators\GoldenSnapshotTest.
 */
class ManufacturingApiTest extends TestCase
{
    use CalculatorFixtures;
    use RefreshDatabase;

    public function test_calculate_returns_the_v1_shape(): void
    {
        $this->seedIndustryTree();
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/v1/calculators/manufacturing', self::feeRequest());

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'product' => ['sell_price', 'price_source', 'total_output', 'total_revenue'],
                    'time' => ['base_time_seconds', 'time_per_run_seconds', 'total_time_seconds'],
                    'materials',
                    'build_cheaper',
                    'scc_fee',
                    'build_fee' => ['has_data', 'eiv', 'sci', 'job_cost', 'tax_rate', 'facility_tax', 'total', 'components_fee', 'total_full_build'],
                    'scenarios' => ['full_buy', 'full_build'],
                    'market_buy',
                ],
                'meta' => ['freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale']],
            ])
            ->assertJsonPath('meta.freshness.source', 'market_orders');

        // Display strings are not part of v1.
        $this->assertArrayNotHasKey('unit_price_formatted', $response->json('data.materials.0'));
        $this->assertArrayNotHasKey('total_time_formatted', $response->json('data.time'));
    }

    public function test_calculate_errors(): void
    {
        $this->fakeEsi();
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/calculators/manufacturing', ['runs' => 0])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['blueprint_type_id', 'runs', 'me_level']);

        $this->actingAs($user)->postJson('/api/v1/calculators/manufacturing', [
            'blueprint_type_id' => 1, 'product_type_id' => 2, 'runs' => 1, 'me_level' => 0,
            'te_level' => 0, 'industry_skill' => 0, 'advanced_industry_skill' => 0,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.blueprint_type_id.0', 'No materials found for this blueprint.');

        $this->asGuest()->postJson('/api/v1/calculators/manufacturing', self::feeRequest())
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_queue_summary(): void
    {
        $items = [
            ['revenue' => 1000000, 'input_cost' => 600000, 'fees' => 55000.5],
            ['revenue' => 250000.75, 'input_cost' => 300000, 'fees' => 12000],
        ];
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/calculators/manufacturing/queue-summary', ['items' => $items])
            ->assertOk()
            ->assertJsonPath('data.items.0.profit', 344999.5)
            ->assertJsonPath('data.items.1.profit', -61999.25)
            ->assertJsonPath('data.totals.revenue', 1250000.75)
            ->assertJsonPath('data.totals.profit', 283000.25);

        $this->actingAs($user)->postJson('/api/v1/calculators/manufacturing/queue-summary', ['items' => []])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $this->asGuest()->postJson('/api/v1/calculators/manufacturing/queue-summary', ['items' => $items])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_skills_for_own_character_only(): void
    {
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterSkills')->andReturn(['skills' => [
            ['skill_id' => 3380, 'trained_skill_level' => 5],
            ['skill_id' => 3388, 'trained_skill_level' => 4],
            ['skill_id' => 45746, 'trained_skill_level' => 3],
            ['skill_id' => 21790, 'trained_skill_level' => 2],
        ]]);
        $this->app->instance(EsiService::class, $esi);

        $user = $this->makeUser();
        $this->makeCharacter($user, 90000001);
        $this->makeCharacter($this->makeUser('other@example.com'), 90000002);

        $this->actingAs($user)->getJson('/api/v1/calculators/manufacturing/skills?character_id=90000001')
            ->assertOk()
            ->assertExactJson(['data' => [
                'character_id' => 90000001,
                'character_name' => 'Pilot 90000001',
                'skills' => ['industry' => 5, 'advanced_industry' => 4],
            ], 'meta' => []]);

        $this->actingAs($user)->getJson('/api/v1/calculators/reactions/skills?character_id=90000001')
            ->assertOk()
            ->assertJsonPath('data.skills', ['reactions' => 3]);

        $this->actingAs($user)->getJson('/api/v1/calculators/invention/skills?character_id=90000001')
            ->assertOk()
            ->assertJsonPath('data.skills.industry', 5)
            ->assertJsonPath('data.skills.levels.21790', 2)
            ->assertJsonPath('data.skills.encryption.1', ['skill_id' => 21790, 'name' => 'Caldari Encryption Methods', 'level' => 2]);

        foreach (['manufacturing', 'invention', 'reactions'] as $calculator) {
            $url = "/api/v1/calculators/{$calculator}/skills";

            $this->actingAs($user)->getJson($url.'?character_id=90000002')
                ->assertStatus(404)
                ->assertJsonPath('code', 'not_found');

            $this->actingAs($user)->getJson($url)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(['character_id']);

            $this->asGuest()->getJson($url.'?character_id=90000001')
                ->assertStatus(401)
                ->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_invention_skill_levels_are_an_object_even_when_empty(): void
    {
        $esi = Mockery::mock(EsiService::class);
        $esi->shouldReceive('getCharacterSkills')->andReturn(null);
        $this->app->instance(EsiService::class, $esi);
        $user = $this->makeUser();
        $this->makeCharacter($user, 90000001);

        $body = $this->actingAs($user)->getJson('/api/v1/calculators/invention/skills?character_id=90000001')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"levels":{}', $body);
    }

    public function test_corporate_export_availability(): void
    {
        $user = $this->makeUser();
        $this->makeCharacter($user, 90000001);

        $this->actingAs($user)->getJson('/api/v1/calculators/corporate-export')
            ->assertOk()
            ->assertExactJson(['data' => ['available' => false, 'corporation_name' => null], 'meta' => []]);

        $this->asGuest()->getJson('/api/v1/calculators/corporate-export')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }
}
