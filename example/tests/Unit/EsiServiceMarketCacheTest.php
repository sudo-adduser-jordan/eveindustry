<?php

namespace Tests\Unit;

use App\Models\MarketHistory;
use App\Models\MarketOrder;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EsiServiceMarketCacheTest extends TestCase
{
    use RefreshDatabase;

    private EsiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        $this->service = new EsiService;
    }

    public function test_get_market_prices_does_not_cache_null_on_failure(): void
    {
        Http::fake([
            '*/markets/prices/*' => Http::response(null, 500),
        ]);

        $result = $this->service->getMarketPrices();
        $this->assertNull($result);
        $this->assertNull(Cache::get('esi.market.prices'));
    }

    public function test_get_market_prices_caches_successful_response(): void
    {
        $priceData = [['type_id' => 34, 'average_price' => 10.0, 'adjusted_price' => 9.5]];

        Http::fake([
            '*/markets/prices/*' => Http::response($priceData, 200),
        ]);

        $result = $this->service->getMarketPrices();
        $this->assertEquals($priceData, $result);
        $this->assertEquals($priceData, Cache::get('esi.market.prices'));
    }

    public function test_get_market_prices_returns_cached_value_without_http_call(): void
    {
        $priceData = [['type_id' => 34, 'average_price' => 10.0]];
        Cache::put('esi.market.prices', $priceData, 86400);

        Http::fake([
            '*/markets/prices/*' => Http::response(null, 500),
        ]);

        $result = $this->service->getMarketPrices();
        $this->assertEquals($priceData, $result);

        Http::assertNothingSent();
    }

    public function test_get_market_region_orders_does_not_cache_null_on_failure(): void
    {
        Http::fake([
            '*/markets/*/orders/*' => Http::response(null, 500),
        ]);

        $result = $this->service->getMarketRegionOrders(10000002, 34, 'sell');
        $this->assertNull($result);
        $this->assertNull(Cache::get('esi.market.orders.10000002.34.sell'));
    }

    public function test_get_market_region_orders_caches_successful_response(): void
    {
        $orderData = [['order_id' => 1, 'price' => 10.0, 'is_buy_order' => false]];

        Http::fake([
            '*/markets/*/orders/*' => Http::response($orderData, 200),
        ]);

        $result = $this->service->getMarketRegionOrders(10000002, 34, 'sell');
        $this->assertEquals($orderData, $result);
        $this->assertEquals($orderData, Cache::get('esi.market.orders.10000002.34.sell'));
    }

    public function test_get_market_region_orders_returns_cached_value_without_http_call(): void
    {
        $orderData = [['order_id' => 1, 'price' => 10.0]];
        Cache::put('esi.market.orders.10000002.34.sell', $orderData, 3600);

        Http::fake([
            '*/markets/*/orders/*' => Http::response(null, 500),
        ]);

        $result = $this->service->getMarketRegionOrders(10000002, 34, 'sell');
        $this->assertEquals($orderData, $result);

        Http::assertNothingSent();
    }

    public function test_fetch_market_region_history_from_esi_does_not_cache_null_on_failure(): void
    {
        Http::fake([
            '*/markets/*/history/*' => Http::response(null, 500),
        ]);

        $result = $this->service->fetchMarketRegionHistoryFromEsi(10000002, 34);
        $this->assertNull($result);
        $this->assertNull(Cache::get('esi.market.history.10000002.34'));
    }

    public function test_fetch_market_region_history_from_esi_caches_successful_response(): void
    {
        $historyData = [['date' => '2024-01-01', 'volume' => 1000, 'average' => 10.0]];

        Http::fake([
            '*/markets/*/history/*' => Http::response($historyData, 200),
        ]);

        $result = $this->service->fetchMarketRegionHistoryFromEsi(10000002, 34);
        $this->assertEquals($historyData, $result);
        $this->assertEquals($historyData, Cache::get('esi.market.history.10000002.34'));
    }

    public function test_fetch_market_region_history_from_esi_returns_cached_value_without_http_call(): void
    {
        $historyData = [['date' => '2024-01-01', 'volume' => 1000]];
        Cache::put('esi.market.history.10000002.34', $historyData, 21600);

        Http::fake([
            '*/markets/*/history/*' => Http::response(null, 500),
        ]);

        $result = $this->service->fetchMarketRegionHistoryFromEsi(10000002, 34);
        $this->assertEquals($historyData, $result);

        Http::assertNothingSent();
    }

    public function test_get_market_prices_retries_after_failure_without_cached_null(): void
    {
        $priceData = [['type_id' => 34, 'average_price' => 10.0]];

        Http::fake([
            '*/markets/prices/*' => Http::sequence()
                ->push(null, 500)
                ->push($priceData, 200),
        ]);

        // First call fails
        $result1 = $this->service->getMarketPrices();
        $this->assertNull($result1);

        // Second call should retry (not serve cached null)
        $result2 = $this->service->getMarketPrices();
        $this->assertEquals($priceData, $result2);
    }

    public function test_get_market_region_orders_does_not_cache_null_on_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Connection timeout');
        });

        $result = $this->service->getMarketRegionOrders(10000002, 34, 'sell');
        $this->assertNull($result);
        $this->assertNull(Cache::get('esi.market.orders.10000002.34.sell'));
    }

    public function test_get_market_region_history_reads_imported_history_without_esi(): void
    {
        MarketHistory::create([
            'region_id' => 10000002,
            'type_id' => 34,
            'date' => '2024-01-02',
            'average' => 10.5,
            'highest' => 11.0,
            'lowest' => 10.0,
            'volume' => 1000,
            'order_count' => 50,
        ]);
        Http::fake();

        $result = $this->service->getMarketRegionHistory(10000002, 34);

        $this->assertCount(1, $result);
        $this->assertEquals('2024-01-02', $result[0]['date']);
        $this->assertEquals(1000, $result[0]['volume']);
        Http::assertNothingSent();
    }

    public function test_get_market_region_history_returns_null_without_imported_history(): void
    {
        // History comes from the everef import; there is no ESI fallback
        Http::fake();

        $this->assertNull($this->service->getMarketRegionHistory(10000002, 34));
        Http::assertNothingSent();
    }

    public function test_fetch_market_region_history_from_esi_does_not_cache_null_on_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Connection timeout');
        });

        $result = $this->service->fetchMarketRegionHistoryFromEsi(10000002, 34);
        $this->assertNull($result);
        $this->assertNull(Cache::get('esi.market.history.10000002.34'));
    }

    public function test_get_market_prices_does_not_cache_null_on_exception(): void
    {
        Http::fake(function () {
            throw new \Exception('Connection timeout');
        });

        $result = $this->service->getMarketPrices();
        $this->assertNull($result);
        $this->assertNull(Cache::get('esi.market.prices'));
    }

    public function test_get_market_region_all_orders_returns_orders_from_database(): void
    {
        MarketOrder::insert([
            [
                'order_id'      => 1,
                'region_id'     => 10000002,
                'type_id'       => 34,
                'is_buy_order'  => false,
                'price'         => 5.50,
                'volume_remain' => 1000,
                'volume_total'  => 5000,
                'min_volume'    => 1,
                'batch_id'      => 'test-batch',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'order_id'      => 2,
                'region_id'     => 10000002,
                'type_id'       => 35,
                'is_buy_order'  => true,
                'price'         => 3.00,
                'volume_remain' => 500,
                'volume_total'  => 500,
                'min_volume'    => 1,
                'batch_id'      => 'test-batch',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);

        $result = $this->service->getMarketRegionAllOrders(10000002);

        $this->assertCount(2, $result);
        $this->assertEquals(34, $result[0]['type_id']);
        $this->assertEquals(5.50, $result[0]['price']);
        $this->assertFalse($result[0]['is_buy_order']);
        $this->assertTrue($result[1]['is_buy_order']);

        // No HTTP calls should be made — data comes from DB
        Http::assertNothingSent();
    }

    public function test_get_market_region_all_orders_returns_empty_when_no_data(): void
    {
        $result = $this->service->getMarketRegionAllOrders(10000002);

        $this->assertEmpty($result);
    }

    public function test_get_market_region_all_orders_filters_by_region(): void
    {
        MarketOrder::insert([
            [
                'order_id'      => 10,
                'region_id'     => 10000002,
                'type_id'       => 34,
                'is_buy_order'  => false,
                'price'         => 5.00,
                'volume_remain' => 100,
                'volume_total'  => 100,
                'batch_id'      => 'test',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'order_id'      => 11,
                'region_id'     => 10000043,
                'type_id'       => 34,
                'is_buy_order'  => false,
                'price'         => 6.00,
                'volume_remain' => 200,
                'volume_total'  => 200,
                'batch_id'      => 'test',
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);

        $forgeOrders = $this->service->getMarketRegionAllOrders(10000002);
        $domainOrders = $this->service->getMarketRegionAllOrders(10000043);

        $this->assertCount(1, $forgeOrders);
        $this->assertCount(1, $domainOrders);
        $this->assertEquals(10, $forgeOrders[0]['order_id']);
        $this->assertEquals(11, $domainOrders[0]['order_id']);
    }
}
