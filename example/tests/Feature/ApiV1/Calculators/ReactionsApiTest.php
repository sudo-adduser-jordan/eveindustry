<?php

namespace Tests\Feature\ApiV1\Calculators;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Calculators\CalculatorFixtures;
use Tests\TestCase;

/**
 * Shapes, errors and auth of the reaction endpoints; their numbers are
 * pinned by Tests\Feature\Calculators\GoldenSnapshotTest.
 */
class ReactionsApiTest extends TestCase
{
    use CalculatorFixtures;
    use RefreshDatabase;

    public function test_reaction_calculate(): void
    {
        $this->seedIndustryTree();
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/calculators/reactions', self::reactionsRequest())
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['product', 'time', 'materials', 'summary' => ['material_cost', 'scc_fee', 'profit', 'profit_per_unit', 'profit_margin']],
                'meta' => ['freshness' => ['prices_as_of', 'source', 'stale']],
            ]);

        $this->actingAs($user)->postJson('/api/v1/calculators/reactions', ['formula_type_id' => 1, 'product_type_id' => 2, 'runs' => 1, 'reactions_skill' => 0])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.formula_type_id.0', 'No materials found for this reaction formula.');

        $this->actingAs($user)->postJson('/api/v1/calculators/reactions', ['runs' => 0, 'structure_te_bonus' => 31])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['formula_type_id', 'product_type_id', 'runs', 'reactions_skill', 'structure_te_bonus']);

        $this->asGuest()->postJson('/api/v1/calculators/reactions', self::reactionsRequest())
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_reaction_planner(): void
    {
        $this->seedIndustryTree();
        $user = $this->makeUser();

        $this->actingAs($user)->postJson('/api/v1/calculators/reaction-planner', self::plannerRequest())
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['effective_rig_bonus', 'composite_outputs', 'composite_slots', 'intermediate_slots', 'raw_materials', 'slots', 'totals', 'fees', 'profit', 'profit_margin'],
                'meta' => ['freshness'],
            ]);

        $this->actingAs($user)->postJson('/api/v1/calculators/reaction-planner', ['entries' => [], 'security_space' => 'highsec'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['entries', 'security_space']);

        $this->asGuest()->postJson('/api/v1/calculators/reaction-planner', self::plannerRequest())
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_reaction_browser_is_paginated(): void
    {
        $this->seedIndustryTree();
        $user = $this->makeUser();

        $response = $this->actingAs($user)->postJson('/api/v1/calculators/reaction-browser', self::reactionBrowserRequest() + ['per_page' => 1, 'page' => 2]);

        $response->assertOk()
            ->assertJsonPath('meta.pagination', ['page' => 2, 'per_page' => 1, 'total' => 2, 'last_page' => 2])
            ->assertJsonPath('meta.effective_rig_bonus', 2.4)
            ->assertJsonPath('meta.freshness.source', 'market_orders')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.blueprint_type_id', 77006);

        $this->actingAs($user)->postJson('/api/v1/calculators/reaction-browser', ['runs' => 0, 'security_space' => 'highsec'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['runs', 'security_space']);
        $this->actingAs($user)->postJson('/api/v1/calculators/reaction-browser', ['runs' => 1, 'per_page' => 201])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
        $this->asGuest()->postJson('/api/v1/calculators/reaction-browser', ['runs' => 1])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }
}
