<?php

namespace Tests\Feature\ApiV1\Market;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Market\MarketFixtures;
use Tests\TestCase;

class RegionArbitrageTest extends TestCase
{
    use MarketFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/market/region-arbitrage')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');
    }

    public function test_returns_paginated_opportunities_with_freshness_per_region(): void
    {
        $this->seedArbitrageScenario();

        $response = $this->actingAs($this->marketUser())->getJson('/api/v1/market/region-arbitrage');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['type_id', 'type_name', 'volume_m3', 'buy_price', 'sell_price', 'net_profit_per_unit',
                    'net_margin_pct', 'roi_pct', 'daily_volume', 'daily_isk_profit', 'cargo_value_m3']],
                'meta' => [
                    'buy_region' => ['region_id', 'region_name'],
                    'sell_region' => ['region_id', 'region_name'],
                    'freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale'],
                    'freshness_by_region' => ['10000002', '10000043'],
                    'pagination' => ['page', 'per_page', 'total', 'last_page'],
                ],
            ])
            ->assertJsonPath('meta.buy_region.region_id', 10000002)
            ->assertJsonPath('meta.sell_region.region_name', 'Domain (Amarr)')
            ->assertJsonPath('meta.freshness.source', 'market_orders')
            ->assertJsonPath('meta.freshness_by_region.10000043.source', 'market_orders')
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('meta.pagination.per_page', 50)
            ->assertJsonPath('data.0.type_id', 34)
            ->assertJsonPath('data.0.net_profit_per_unit', 44.6)
            ->assertJsonPath('data.0.cargo_value_m3', 4460)
            ->assertJsonPath('data.1.type_name', 'Pyerite');

        $this->actingAs($this->marketUser())->getJson('/api/v1/market/region-arbitrage?per_page=1&page=2&transport_isk_m3=100')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type_id', 35)
            ->assertJsonPath('meta.pagination.last_page', 2);
    }

    public function test_same_region_is_a_validation_error(): void
    {
        $user = $this->marketUser();

        $this->actingAs($user)->getJson('/api/v1/market/region-arbitrage?buy_region_id=10000043&sell_region_id=10000043')
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors('sell_region_id');

        // The defaults are Jita -> Amarr, so buying in Amarr alone collides.
        $this->actingAs($user)->getJson('/api/v1/market/region-arbitrage?buy_region_id=10000043')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sell_region_id');
    }

    public function test_validation(): void
    {
        $user = $this->marketUser();

        foreach (['buy_region_id=123', 'sell_region_id=abc', 'transport_isk_m3=-1', 'min_daily_volume=-1', 'per_page=500'] as $query) {
            $this->actingAs($user)->getJson('/api/v1/market/region-arbitrage?'.$query)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(explode('=', $query)[0]);
        }
    }
}
