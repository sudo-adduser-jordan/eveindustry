<?php

namespace Tests\Feature\ApiV1\Market;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Market\MarketFixtures;
use Tests\TestCase;

class StationTradingTest extends TestCase
{
    use MarketFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
        $this->fakeEsi();
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/market/station-trading')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_paginates_the_full_scan_with_freshness(): void
    {
        $this->seedStationTradingScenario();

        $response = $this->actingAs($this->marketUser())->getJson('/api/v1/market/station-trading?per_page=2');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['type_id', 'type_name', 'best_buy', 'best_sell', 'gross_margin_pct', 'net_profit_per_unit',
                    'net_margin_pct', 'roi_pct', 'break_even', 'daily_volume', 'daily_isk_profit', 'buy_order_count', 'sell_order_count']],
                'meta' => ['region' => ['region_id', 'region_name'], 'freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale'], 'pagination'],
            ])
            ->assertJsonPath('meta.region.region_id', 10000002)
            ->assertJsonPath('meta.region.region_name', 'The Forge (Jita)')
            ->assertJsonPath('meta.freshness.source', 'market_orders')
            ->assertJsonPath('meta.freshness.prices_as_of', '2026-10-21T12:00:00Z')
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 2, 'total' => 3, 'last_page' => 2])
            ->assertJsonPath('data.0.type_id', 34)
            ->assertJsonPath('data.0.daily_isk_profit', 742)
            ->assertJsonPath('data.1.type_id', 38);

        $this->actingAs($this->marketUser())->getJson('/api/v1/market/station-trading?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type_id', 35);
    }

    public function test_is_not_capped_at_the_old_limit_default(): void
    {
        // 120 qualifying types; the old route would stop at 100.
        for ($typeId = 1000; $typeId < 1120; $typeId++) {
            $this->seedOrder($typeId, false, 20.0);
            $this->seedOrder($typeId, true, 10.0);
        }

        $this->actingAs($this->marketUser())->getJson('/api/v1/market/station-trading?per_page=200')
            ->assertOk()
            ->assertJsonCount(120, 'data')
            ->assertJsonPath('meta.pagination.total', 120);
    }

    public function test_default_per_page_is_50(): void
    {
        $this->actingAs($this->marketUser())->getJson('/api/v1/market/station-trading')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.per_page', 50)
            ->assertJsonPath('meta.freshness.stale', true);
    }

    public function test_validation(): void
    {
        $user = $this->marketUser();

        foreach (['region_id=123', 'broker_fee=11', 'sales_tax=-1', 'min_margin=101', 'per_page=201', 'page=0'] as $query) {
            $this->actingAs($user)->getJson('/api/v1/market/station-trading?'.$query)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(explode('=', $query)[0]);
        }
    }
}
