<?php

namespace Tests\Unit;

use App\Models\EveType;
use App\Models\MarketOrder;
use App\Services\EsiService;
use App\Services\MarketTradingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketTradingServiceTest extends TestCase
{
    use RefreshDatabase;

    private MarketTradingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        // Use a mock EsiService — these unit tests only exercise the pure-math methods
        $this->service = new MarketTradingService(
            $this->createMock(EsiService::class)
        );
    }

    // ----------------------------------------------------------------
    // calcStationTradeProfit
    // ----------------------------------------------------------------

    public function test_station_trade_profit_basic(): void
    {
        // Buy at 100 ISK, sell at 150 ISK, 3% broker fee each side, 3.6% sales tax
        $result = $this->service->calcStationTradeProfit(100.0, 150.0, 3.0, 3.6);

        $this->assertGreaterThan(0, $result['net_profit_per_unit']);
        $this->assertGreaterThan(0, $result['net_margin_pct']);
        $this->assertGreaterThan(0, $result['roi_pct']);
        $this->assertGreaterThan(0, $result['gross_margin_pct']);
    }

    public function test_station_trade_profit_gross_margin(): void
    {
        // 100 buy / 200 sell → gross spread is 50%
        $result = $this->service->calcStationTradeProfit(100.0, 200.0);

        $this->assertEqualsWithDelta(50.0, $result['gross_margin_pct'], 0.01);
    }

    public function test_station_trade_profit_zero_when_inverted(): void
    {
        // sell <= buy → no opportunity
        $result = $this->service->calcStationTradeProfit(200.0, 100.0);

        $this->assertEquals(0.0, $result['net_profit_per_unit']);
        $this->assertEquals(0.0, $result['net_margin_pct']);
        $this->assertEquals(0.0, $result['roi_pct']);
    }

    public function test_station_trade_profit_zero_when_equal(): void
    {
        $result = $this->service->calcStationTradeProfit(100.0, 100.0);

        $this->assertEquals(0.0, $result['net_profit_per_unit']);
    }

    public function test_station_trade_profit_zero_when_buy_price_zero(): void
    {
        $result = $this->service->calcStationTradeProfit(0.0, 100.0);

        $this->assertEquals(0.0, $result['net_profit_per_unit']);
    }

    public function test_station_trade_profit_fees_reduce_net_from_gross(): void
    {
        $result = $this->service->calcStationTradeProfit(100.0, 200.0, 3.0, 3.6);

        // Net profit should be less than gross spread (200 - 100 = 100)
        $this->assertLessThan(100.0, $result['net_profit_per_unit']);
    }

    public function test_station_trade_break_even_is_above_buy_price(): void
    {
        $result = $this->service->calcStationTradeProfit(100.0, 200.0, 3.0, 3.6);

        $this->assertGreaterThan(100.0, $result['break_even']);
    }

    public function test_station_trade_higher_fees_lower_profit(): void
    {
        $low  = $this->service->calcStationTradeProfit(100.0, 150.0, 2.0, 2.0);
        $high = $this->service->calcStationTradeProfit(100.0, 150.0, 5.0, 8.0);

        $this->assertGreaterThan($high['net_profit_per_unit'], $low['net_profit_per_unit']);
    }

    // ----------------------------------------------------------------
    // calcArbitrageProfit
    // ----------------------------------------------------------------

    public function test_arbitrage_profit_basic(): void
    {
        // Buy at 100 ISK in region A, sell at 150 ISK in region B
        $result = $this->service->calcArbitrageProfit(100.0, 150.0, 3.0, 3.6);

        $this->assertGreaterThan(0, $result['net_profit_per_unit']);
        $this->assertGreaterThan(0, $result['net_margin_pct']);
        $this->assertGreaterThan(0, $result['roi_pct']);
    }

    public function test_arbitrage_profit_zero_when_inverted(): void
    {
        $result = $this->service->calcArbitrageProfit(200.0, 100.0);

        $this->assertLessThanOrEqual(0, $result['net_profit_per_unit']);
    }

    public function test_arbitrage_profit_transport_cost_reduces_net(): void
    {
        $noTransport   = $this->service->calcArbitrageProfit(100.0, 150.0, 3.0, 3.6, 0.0, 10.0);
        $withTransport = $this->service->calcArbitrageProfit(100.0, 150.0, 3.0, 3.6, 500.0, 10.0);

        $this->assertGreaterThan($withTransport['net_profit_per_unit'], $noTransport['net_profit_per_unit']);
    }

    public function test_arbitrage_profit_no_transport_cost_when_volume_zero(): void
    {
        $noVol  = $this->service->calcArbitrageProfit(100.0, 150.0, 3.0, 3.6, 1000.0, 0.0);
        $withVol = $this->service->calcArbitrageProfit(100.0, 150.0, 3.0, 3.6, 1000.0, 5.0);

        // Zero volume → transport ISK per unit = 0
        $this->assertGreaterThan($withVol['net_profit_per_unit'], $noVol['net_profit_per_unit']);
    }

    public function test_arbitrage_profit_zero_on_zero_prices(): void
    {
        $result = $this->service->calcArbitrageProfit(0.0, 150.0);
        $this->assertEquals(0.0, $result['net_profit_per_unit']);

        $result2 = $this->service->calcArbitrageProfit(100.0, 0.0);
        $this->assertEquals(0.0, $result2['net_profit_per_unit']);
    }

    // ----------------------------------------------------------------
    // scanStationTrading — regression tests for "no results" bugs
    // ----------------------------------------------------------------

    /**
     * Helper to seed a market_orders row.
     */
    private function seedOrder(int $typeId, bool $isBuyOrder, float $price, int $regionId = 10000002): void
    {
        static $seq = 1;
        MarketOrder::create([
            'order_id'      => $seq++,
            'region_id'     => $regionId,
            'type_id'       => $typeId,
            'is_buy_order'  => $isBuyOrder,
            'price'         => $price,
            'volume_remain' => 100,
            'volume_total'  => 100,
            'min_volume'    => 1,
            'location_id'   => 60003760,
            'duration'      => 90,
            'range'         => 'station',
            'issued'        => now(),
            'batch_id'      => 'test-batch',
        ]);
    }

    /**
     * Bug: when getMarketRegionHistory returns null (ESI failure), $dailyVolume
     * stayed at 0 and the minDailyVolume filter (default 5) eliminated EVERY
     * candidate, returning an empty result set.
     *
     * Fix: volume filter is skipped when history is null; the item is included.
     */
    public function test_scan_station_trading_includes_item_when_history_unavailable(): void
    {
        $esi = $this->createMock(EsiService::class);

        // Seed orders into the database
        $this->seedOrder(34, false, 150.0); // sell
        $this->seedOrder(34, true,  100.0); // buy

        // History unavailable — ESI failure
        $esi->method('getMarketRegionHistory')->willReturn(null);

        $service = new MarketTradingService($esi);
        $results = $service->scanStationTrading(
            MarketTradingService::REGION_THE_FORGE,
            3.0,   // brokerFee
            3.6,   // salesTax
            5.0,   // minMarginPct
            5,     // minDailyVolume
            100
        );

        // Must not be empty — the item should pass through even with null history
        $this->assertNotEmpty($results);
        $this->assertEquals(34, $results[0]['type_id']);
        // Volume reported as 0 (unknown) in the result
        $this->assertEquals(0, $results[0]['daily_volume']);
    }

    /**
     * When history IS available with sufficient volume, the item appears normally.
     */
    /**
     * Bug: the SQL built the fallback name with 'Type #' || type_id, which
     * concatenates on SQLite but is a logical OR on MySQL (giving "1").
     * The name is now built in PHP so both databases agree.
     */
    public function test_scan_station_trading_names_unknown_types_on_any_database(): void
    {
        $esi = $this->createMock(EsiService::class);
        $esi->method('getMarketRegionHistory')->willReturn(null);

        // No eve_types row for 99001
        $this->seedOrder(99001, false, 150.0);
        $this->seedOrder(99001, true, 100.0);

        $results = (new MarketTradingService($esi))->scanStationTrading(
            MarketTradingService::REGION_THE_FORGE, 3.0, 3.6, 5.0, 5, 100
        );

        $this->assertCount(1, $results);
        $this->assertSame('Type #99001', $results[0]['type_name']);
    }

    public function test_scan_station_trading_uses_known_type_name(): void
    {
        $esi = $this->createMock(EsiService::class);
        $esi->method('getMarketRegionHistory')->willReturn(null);

        \App\Models\EveType::create(['type_id' => 34, 'name' => 'Tritanium', 'published' => true]);
        $this->seedOrder(34, false, 150.0);
        $this->seedOrder(34, true, 100.0);

        $results = (new MarketTradingService($esi))->scanStationTrading(
            MarketTradingService::REGION_THE_FORGE, 3.0, 3.6, 5.0, 5, 100
        );

        $this->assertSame('Tritanium', $results[0]['type_name']);
    }

    public function test_scan_station_trading_finds_opportunity_with_history(): void
    {
        $esi = $this->createMock(EsiService::class);

        // Seed orders into the database
        $this->seedOrder(34, false, 150.0);
        $this->seedOrder(34, true,  100.0);

        $esi->method('getMarketRegionHistory')->willReturn([
            ['date' => '2024-01-01', 'volume' => 3000],
            ['date' => '2024-01-02', 'volume' => 3000],
        ]);

        $service = new MarketTradingService($esi);
        $results = $service->scanStationTrading(
            MarketTradingService::REGION_THE_FORGE,
            3.0, 3.6, 5.0, 5, 100
        );

        $this->assertNotEmpty($results);
        $this->assertEquals(34, $results[0]['type_id']);
        $this->assertEquals(3000, $results[0]['daily_volume']);
    }

    /**
     * When history returns a non-null empty array (item never traded), the
     * volume is correctly computed as 0 and the item IS filtered out by
     * the minimum volume threshold.
     */
    public function test_scan_station_trading_filters_item_with_zero_history(): void
    {
        $esi = $this->createMock(EsiService::class);

        // Seed orders into the database
        $this->seedOrder(34, false, 150.0);
        $this->seedOrder(34, true,  100.0);

        // ESI returned data successfully, but the item has no trading history
        $esi->method('getMarketRegionHistory')->willReturn([]);

        $service = new MarketTradingService($esi);
        $results = $service->scanStationTrading(
            MarketTradingService::REGION_THE_FORGE,
            3.0, 3.6, 5.0, 5, 100
        );

        // No history → dailyVolume = 0 < minDailyVolume 5 → filtered
        $this->assertEmpty($results);
    }

    /**
     * When no orders exist in the database, the scan
     * returns [] immediately and does not crash.
     */
    public function test_scan_station_trading_returns_empty_when_orders_empty(): void
    {
        $esi = $this->createMock(EsiService::class);

        $service = new MarketTradingService($esi);
        $results = $service->scanStationTrading();

        $this->assertEmpty($results);
    }

    // ----------------------------------------------------------------
    // scanRegionArbitrage — regression tests for "no results" bugs
    // ----------------------------------------------------------------

    /**
     * Bug: same null-history volume-filter issue in scanRegionArbitrage.
     */
    public function test_scan_region_arbitrage_includes_item_when_history_unavailable(): void
    {
        $esi = $this->createMock(EsiService::class);

        // Source region (Jita): sell order at 100
        // Dest region (Amarr): buy order at 150
        $esi->method('getRegionBestPrices')->willReturnCallback(
            fn (int $regionId, bool $buyOrders) => match ([$regionId, $buyOrders]) {
                [MarketTradingService::REGION_THE_FORGE, false] => [34 => 100.0],
                [MarketTradingService::REGION_DOMAIN, true] => [34 => 150.0],
                default => [],
            }
        );

        $esi->method('getMarketRegionHistory')->willReturn(null);
        $esi->method('getTypeInfo')->willReturn(['name' => 'Tritanium', 'packaged_volume' => 0.01]);

        $service = new MarketTradingService($esi);
        $results = $service->scanRegionArbitrage(
            MarketTradingService::REGION_THE_FORGE,
            MarketTradingService::REGION_DOMAIN,
            3.0, 3.6, 0.0, 5.0, 5, 100
        );

        // Must not be empty when history is unavailable
        $this->assertNotEmpty($results);
        $this->assertEquals(34, $results[0]['type_id']);
    }

    /**
     * Source region: only sell orders should be included in the buy-price index.
     * Buy orders in the source region must be ignored.
     */
    public function test_scan_region_arbitrage_uses_only_sell_orders_in_source(): void
    {
        $esi = $this->createMock(EsiService::class);

        // No sell orders in the source region, only a buy order in the destination.
        $esi->method('getRegionBestPrices')->willReturnCallback(
            fn (int $regionId, bool $buyOrders) => $regionId === MarketTradingService::REGION_DOMAIN && $buyOrders ? [34 => 150.0] : []
        );

        $esi->method('getMarketRegionHistory')->willReturn(null);
        $esi->method('getTypeInfo')->willReturn(['name' => 'Tritanium', 'packaged_volume' => 0.01]);

        $service = new MarketTradingService($esi);
        $results = $service->scanRegionArbitrage(
            MarketTradingService::REGION_THE_FORGE,
            MarketTradingService::REGION_DOMAIN,
        );

        // No sell orders in source → no arbitrage possible
        $this->assertEmpty($results);
    }
}

