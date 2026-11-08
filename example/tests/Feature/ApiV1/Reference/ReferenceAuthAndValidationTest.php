<?php

namespace Tests\Feature\ApiV1\Reference;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Reference\SeedsReferenceData;
use Tests\TestCase;

/**
 * Cross-cutting checks for every /api/v1 reference endpoint: 401 with a
 * code when logged out, 422 with a code on a missing or short q, and 404
 * with a code on unknown ids.
 */
class ReferenceAuthAndValidationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsReferenceData;

    private const ENDPOINTS = [
        '/api/v1/blueprints?q=rif',
        '/api/v1/blueprints/691',
        '/api/v1/types?q=rif',
        '/api/v1/types/587',
        '/api/v1/reactions/formulas?q=cad',
        '/api/v1/reactions/formulas/46166',
        '/api/v1/invention/items?q=wol',
        '/api/v1/invention/items/11371',
        '/api/v1/manufacturing/categories',
        '/api/v1/stations?q=jita',
        '/api/v1/stations/60003760',
        '/api/v1/systems?q=jita',
        '/api/v1/systems/30000142',
        '/api/v1/systems/30000142/cost-indices',
        '/api/v1/systems/30000142/stations',
        '/api/v1/market-groups',
        '/api/v1/market-groups/tree',
        '/api/v1/market-groups/64',
        '/api/v1/corporations?q=goons',
    ];

    private const SEARCHES = [
        '/api/v1/blueprints',
        '/api/v1/types',
        '/api/v1/reactions/formulas',
        '/api/v1/invention/items',
        '/api/v1/stations',
        '/api/v1/systems',
        '/api/v1/corporations',
    ];

    private const NOT_FOUND = [
        '/api/v1/blueprints/123',
        '/api/v1/types/123',
        '/api/v1/reactions/formulas/691',
        '/api/v1/invention/items/587',
        '/api/v1/stations/1',
        '/api/v1/systems/1',
        '/api/v1/systems/1/stations',
        '/api/v1/market-groups/999',
    ];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->blockOutsideHttp();
        $this->user = $this->makeUser();
    }

    public function test_every_endpoint_requires_auth(): void
    {
        foreach (self::ENDPOINTS as $url) {
            $this->getJson($url)
                ->assertStatus(401)
                ->assertJsonPath('code', 'unauthenticated');
        }
    }

    public function test_searches_require_q_of_at_least_two_characters(): void
    {
        foreach (self::SEARCHES as $url) {
            $this->actingAs($this->user)->getJson($url)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors('q');

            $this->actingAs($this->user)->getJson("{$url}?q=a")
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed');

            $this->actingAs($this->user)->getJson("{$url}?q=".str_repeat('a', 101))
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed');
        }
    }

    public function test_unknown_ids_are_404_with_code(): void
    {
        $this->seedTypes();
        $this->seedBlueprints();

        foreach (self::NOT_FOUND as $url) {
            $this->actingAs($this->user)->getJson($url)
                ->assertStatus(404)
                ->assertJsonPath('code', 'not_found');
        }
    }

    public function test_non_numeric_ids_do_not_match_a_route(): void
    {
        $this->actingAs($this->user)->getJson('/api/v1/types/abc')
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->actingAs($this->user)->getJson('/api/v1/systems/jita')
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
    }
}
