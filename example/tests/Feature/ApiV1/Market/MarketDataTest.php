<?php

namespace Tests\Feature\ApiV1\Market;

use App\Models\MarketPrice;
use App\Services\MarketTradingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\LegacyApi\Market\MarketFixtures;
use Tests\TestCase;

/**
 * GET /api/v1/market/prices, /history/{typeId} and /orders.
 */
class MarketDataTest extends TestCase
{
    use MarketFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC'));
        $this->fakeEsi();
    }

    public function test_all_require_auth(): void
    {
        foreach (['/api/v1/market/prices?type_ids[]=34', '/api/v1/market/history/34', '/api/v1/market/orders?type_id=34'] as $url) {
            $this->getJson($url)->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
        }
    }

    // ---------------------------------------------------------------- prices

    public function test_prices_with_source_in_request_order(): void
    {
        $this->seedOrder(34, false, 5.0);
        MarketPrice::create(['type_id' => 35, 'region_id' => 10000002, 'average_price' => 12.5, 'fetched_at' => now()]);

        $this->actingAs($this->marketUser())
            ->getJson('/api/v1/market/prices?type_ids[]=36&type_ids[]=34&type_ids[]=35&type_ids[]=34')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    ['type_id' => 36, 'price' => 0.0, 'source' => 'none'],
                    ['type_id' => 34, 'price' => 5.0, 'source' => 'orders'],
                    ['type_id' => 35, 'price' => 12.5, 'source' => 'fallback'],
                ],
                'meta' => [
                    'region_id' => 10000002,
                    'freshness' => [
                        'prices_as_of' => '2026-10-21T12:00:00Z',
                        'costs_as_of' => null,
                        'source' => 'market_orders',
                        'stale' => true, // no precomputed costs or global prices
                    ],
                ],
            ]);
    }

    public function test_prices_in_another_hub(): void
    {
        $this->seedOrder(34, false, 7.0, MarketTradingService::REGION_DOMAIN);

        $this->actingAs($this->marketUser())
            ->getJson('/api/v1/market/prices?type_ids[]=34&region_id=10000043')
            ->assertOk()
            ->assertJsonPath('data.0.price', 7)
            ->assertJsonPath('meta.region_id', 10000043)
            ->assertJsonPath('meta.freshness.source', 'market_orders');
    }

    public function test_prices_validation(): void
    {
        $user = $this->marketUser();

        $this->actingAs($user)->getJson('/api/v1/market/prices')
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('type_ids');
        $this->actingAs($user)->getJson('/api/v1/market/prices?type_ids[]=abc')
            ->assertStatus(422)->assertJsonValidationErrors('type_ids.0');
        $this->actingAs($user)->getJson('/api/v1/market/prices?type_ids[]=34&region_id=10000001')
            ->assertStatus(422)->assertJsonValidationErrors('region_id');

        $tooMany = http_build_query(['type_ids' => range(1, 201)]);
        $this->actingAs($user)->getJson('/api/v1/market/prices?'.$tooMany)
            ->assertStatus(422)->assertJsonValidationErrors('type_ids');
    }

    // --------------------------------------------------------------- history

    public function test_history_newest_first_limited_to_days_and_paginated(): void
    {
        $this->seedType(34, 'Tritanium');
        $volumes = [];
        for ($i = 0; $i < 40; $i++) {
            $volumes[CarbonImmutable::parse('2026-10-20')->subDays($i)->toDateString()] = 100 + $i;
        }
        $this->seedHistory(34, $volumes);
        $this->seedHistory(34, ['2026-10-20' => 999], MarketTradingService::REGION_DOMAIN);

        $response = $this->actingAs($this->marketUser())->getJson('/api/v1/market/history/34?per_page=10');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['date', 'average', 'highest', 'lowest', 'volume', 'order_count']],
                'meta' => ['type_id', 'region_id', 'freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale'], 'pagination'],
            ])
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 10, 'total' => 30, 'last_page' => 3])
            ->assertJsonPath('data.0', [
                'date' => '2026-10-20', 'average' => 100, 'highest' => 110, 'lowest' => 90, 'volume' => 100, 'order_count' => 7,
            ])
            ->assertJsonPath('data.9.date', '2026-10-11');

        $this->actingAs($this->marketUser())->getJson('/api/v1/market/history/34?days=365&page=1&per_page=200')
            ->assertOk()
            ->assertJsonCount(40, 'data')
            ->assertJsonPath('data.39.date', '2026-09-11');

        $this->actingAs($this->marketUser())->getJson('/api/v1/market/history/34?region_id=10000043')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.volume', 999)
            ->assertJsonPath('meta.region_id', 10000043);
    }

    public function test_history_empty_unknown_type_and_validation(): void
    {
        $this->seedType(34, 'Tritanium');
        $user = $this->marketUser();

        $this->actingAs($user)->getJson('/api/v1/market/history/34')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);

        $this->actingAs($user)->getJson('/api/v1/market/history/999999')
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');

        $this->actingAs($user)->getJson('/api/v1/market/history/abc')
            ->assertStatus(404)
            ->assertJsonPath('code', 'not_found');

        foreach (['days=0', 'days=366', 'region_id=1', 'per_page=201'] as $query) {
            $this->actingAs($user)->getJson('/api/v1/market/history/34?'.$query)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors(explode('=', $query)[0]);
        }
    }

    // ---------------------------------------------------------------- orders

    public function test_orders_best_first_by_side(): void
    {
        $this->seedOrder(34, false, 6.0);
        $cheapest = $this->seedOrder(34, false, 5.0);
        $this->seedOrder(34, false, 5.5);
        $this->seedOrder(34, false, 1.0, volumeRemain: 0); // filled, hidden
        $this->seedOrder(34, true, 4.0);
        $highestBuy = $this->seedOrder(34, true, 4.5);
        $this->seedOrder(35, false, 1.0);
        $this->seedOrder(34, false, 2.0, MarketTradingService::REGION_DOMAIN);

        $user = $this->marketUser();

        $response = $this->actingAs($user)->getJson('/api/v1/market/orders?type_id=34');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [['order_id', 'type_id', 'region_id', 'is_buy_order', 'price', 'volume_remain', 'volume_total',
                    'min_volume', 'location_id', 'range', 'duration', 'issued']],
                'meta' => ['type_id', 'region_id', 'side', 'freshness' => ['prices_as_of', 'costs_as_of', 'source', 'stale'], 'pagination'],
            ])
            ->assertJsonPath('meta.side', 'sell')
            ->assertJsonPath('meta.pagination', ['page' => 1, 'per_page' => 50, 'total' => 3, 'last_page' => 1])
            ->assertJsonPath('meta.freshness.source', 'market_orders')
            ->assertJsonPath('data.0.order_id', $cheapest)
            ->assertJsonPath('data.0.issued', '2026-10-21T12:00:00Z')
            ->assertJsonPath('data.0.is_buy_order', false);
        $this->assertSame([5, 5.5, 6], array_column($response->json('data'), 'price'));

        $response = $this->actingAs($user)->getJson('/api/v1/market/orders?type_id=34&side=buy&per_page=1');
        $response->assertOk()
            ->assertJsonPath('data.0.order_id', $highestBuy)
            ->assertJsonPath('data.0.is_buy_order', true)
            ->assertJsonPath('meta.pagination.total', 2);

        $this->actingAs($user)->getJson('/api/v1/market/orders?type_id=34&region_id=10000043')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.price', 2);

        $this->actingAs($user)->getJson('/api/v1/market/orders?type_id=99999')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_orders_validation(): void
    {
        $user = $this->marketUser();

        foreach (['' => 'type_id', 'type_id=abc' => 'type_id', 'type_id=34&side=both' => 'side',
            'type_id=34&region_id=5' => 'region_id', 'type_id=34&per_page=201' => 'per_page'] as $query => $field) {
            $this->actingAs($user)->getJson('/api/v1/market/orders?'.$query)
                ->assertStatus(422)
                ->assertJsonPath('code', 'validation_failed')
                ->assertJsonValidationErrors($field);
        }
    }
}
