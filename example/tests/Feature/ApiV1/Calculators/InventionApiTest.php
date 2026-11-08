<?php

namespace Tests\Feature\ApiV1\Calculators;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Calculators\CalculatorFixtures;
use Tests\Feature\Calculators\InventionFixtures;
use Tests\TestCase;

/**
 * Shapes, errors and auth of the invention endpoints; the numbers are pinned
 * by Tests\Feature\Calculators\GoldenSnapshotTest.
 */
class InventionApiTest extends TestCase
{
    use CalculatorFixtures;
    use InventionFixtures;
    use RefreshDatabase;

    public function test_v1_calculate_returns_the_v1_shape(): void
    {
        $this->seedInvention();

        $this->actingAs($this->makeUser())->postJson('/api/v1/calculators/invention', self::fullRequest())
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'invention' => ['probability', 'expected_attempts', 'total_attempts', 'cost_per_attempt', 'cost_per_success', 'total_cost', 'datacore_cost_per_attempt', 'datacores', 'decryptor'],
                    't1_item', 't2_bpc', 't2_materials', 'material_costs', 't2_job', 'output', 'structure_bonuses',
                    'build_cheaper', 'best', 'scenarios' => ['full_buy', 'full_build'],
                ],
                'meta' => ['freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale']],
            ]);
    }

    public function test_v1_errors(): void
    {
        $this->seedInvention();
        $user = $this->makeUser();

        $request = self::fullRequest();
        $request['t2_type_id'] = 999;
        $this->actingAs($user)->postJson('/api/v1/calculators/invention', $request)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.t2_type_id.0', 'Could not resolve invention chain');

        $this->actingAs($user)->postJson('/api/v1/calculators/invention', ['structure_type' => 'tatara'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['t2_type_id', 'structure_type']);

        $this->asGuest()->postJson('/api/v1/calculators/invention', self::fullRequest())
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }
}
