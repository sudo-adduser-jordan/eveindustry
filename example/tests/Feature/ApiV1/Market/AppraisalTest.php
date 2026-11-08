<?php

namespace Tests\Feature\ApiV1\Market;

use App\Services\MarketFreshnessService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Market\MarketFixtures;
use Tests\TestCase;

class AppraisalTest extends TestCase
{
    use MarketFixtures;
    use RefreshDatabase;

    public function test_requires_auth(): void
    {
        $this->postJson('/api/v1/market/appraisals', ['items' => 'Tritanium'])
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_appraises_items_with_contract_freshness(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
        $this->seedType(34, 'Tritanium');
        $this->seedSingleItemContract(100001, 34, 100, 500000.0);
        $this->seedSingleItemContract(100002, 34, 200, 1200000.0);

        $this->actingAs($this->marketUser())
            ->postJson('/api/v1/market/appraisals', ['items' => "Tritanium\nTritanium\nNope\nTritanium"])
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'items' => [[
                        'type_id' => 34,
                        'name' => 'Tritanium',
                        'quantity' => 3,
                        'unit_price' => 5500.0,
                        'line_total' => 16500.0,
                        'contracts_found' => 2,
                    ]],
                    'total_value' => 16500.0,
                    'unmatched' => ['Nope'],
                ],
                'meta' => [
                    'freshness' => [
                        'prices_as_of' => '2026-10-21T12:00:00Z',
                        'costs_as_of' => null,
                        'source' => 'public_contracts',
                        'stale' => false,
                    ],
                ],
            ]);
    }

    public function test_method_and_filter_and_stale_contracts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', 'UTC'));
        $this->seedType(34, 'Tritanium');
        $this->seedSingleItemContract(100001, 34, 100, 500000.0);
        $this->seedSingleItemContract(100002, 34, 100, 100000.0, false);
        $this->travelTo(CarbonImmutable::parse('2026-10-21 08:00:00', 'UTC')->addHours(MarketFreshnessService::CONTRACTS_STALE_AFTER_HOURS + 1));

        $this->actingAs($this->marketUser())
            ->postJson('/api/v1/market/appraisals', ['items' => 'Tritanium', 'filter' => 'sell', 'method' => 'lowest'])
            ->assertOk()
            ->assertJsonPath('data.items.0.unit_price', 5000)
            ->assertJsonPath('data.items.0.contracts_found', 1)
            ->assertJsonPath('meta.freshness.stale', true);
    }

    public function test_no_contracts_means_source_none(): void
    {
        $this->actingAs($this->marketUser())
            ->postJson('/api/v1/market/appraisals', ['items' => 'Foo'])
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.unmatched', ['Foo'])
            ->assertJsonPath('meta.freshness.source', 'none')
            ->assertJsonPath('meta.freshness.stale', true);
    }

    public function test_validation(): void
    {
        $user = $this->marketUser();

        $this->actingAs($user)->postJson('/api/v1/market/appraisals', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('items');

        $this->actingAs($user)->postJson('/api/v1/market/appraisals', ['items' => 'x', 'filter' => 'nope', 'method' => 'median'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['filter', 'method']);
    }
}
