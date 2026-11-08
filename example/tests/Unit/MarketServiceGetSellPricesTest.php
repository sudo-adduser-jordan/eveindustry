<?php

namespace Tests\Unit;

use App\Models\MarketOrder;
use App\Models\MarketPrice;
use App\Services\EsiService;
use App\Services\MarketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class MarketServiceGetSellPricesTest extends TestCase
{
    use RefreshDatabase;

    private MarketService $marketService;

    protected function setUp(): void
    {
        parent::setUp();

        $esi = Mockery::mock(EsiService::class);
        $this->marketService = new MarketService($esi);
    }

    private function createSellOrder(int $typeId, float $price, int $volumeRemain = 1000, ?string $issued = null): void
    {
        static $orderId = 7000000;
        $orderId++;

        MarketOrder::create([
            'order_id' => $orderId,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'type_id' => $typeId,
            'is_buy_order' => false,
            'price' => $price,
            'volume_remain' => $volumeRemain,
            'volume_total' => $volumeRemain,
            'issued' => $issued ?? now()->toDateTimeString(),
        ]);
    }

    public function test_returns_empty_for_empty_input(): void
    {
        $this->assertEmpty($this->marketService->getSellPrices([]));
    }

    public function test_single_order_returns_its_price(): void
    {
        $this->createSellOrder(100, 500, 1000);

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(500.0, $result[100]);
    }

    public function test_computes_average_of_lowest_five_percent_by_volume(): void
    {
        // Total volume = 100+100+100+100+600 = 1000
        // 5% of 1000 = 50 units
        // Cheapest orders first: 100@100, 200@100, 300@100, 400@100, 500@600
        // 50 units all come from the first order at price 100
        // Average = 100.0
        $this->createSellOrder(100, 100, 100);
        $this->createSellOrder(100, 200, 100);
        $this->createSellOrder(100, 300, 100);
        $this->createSellOrder(100, 400, 100);
        $this->createSellOrder(100, 500, 600);

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(100.0, $result[100]);
    }

    public function test_five_percent_spans_multiple_orders(): void
    {
        // Total volume = 30+30+940 = 1000
        // 5% of 1000 = 50 units
        // Sorted: 100@30, 200@30, 500@940
        // First 30 units at 100, then 20 more units at 200
        // Weighted avg = (100*30 + 200*20) / 50 = (3000+4000)/50 = 140.0
        $this->createSellOrder(100, 100, 30);
        $this->createSellOrder(100, 200, 30);
        $this->createSellOrder(100, 500, 940);

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(140.0, $result[100]);
    }

    public function test_uniform_orders_return_weighted_average(): void
    {
        // 20 orders of 50 volume each = 1000 total, all at same price
        // 5% = 50 units = 1 order worth
        for ($i = 0; $i < 20; $i++) {
            $this->createSellOrder(100, 500, 50);
        }

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(500.0, $result[100]);
    }

    public function test_ignores_buy_orders(): void
    {
        static $buyOrderId = 6000000;
        $buyOrderId++;
        MarketOrder::create([
            'order_id' => $buyOrderId,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'type_id' => 100,
            'is_buy_order' => true,
            'price' => 50,
            'volume_remain' => 1000,
            'volume_total' => 1000,
            'issued' => now()->toDateTimeString(),
        ]);

        $this->createSellOrder(100, 500, 1000);

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(500.0, $result[100]);
    }

    public function test_ignores_fulfilled_orders(): void
    {
        $this->createSellOrder(100, 100, 0); // fulfilled
        $this->createSellOrder(100, 500, 1000);

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(500.0, $result[100]);
    }

    public function test_falls_back_to_market_prices_sell_min(): void
    {
        MarketPrice::create([
            'type_id' => 100,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'sell_min' => 750,
            'average_price' => 999,
        ]);

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(750.0, $result[100]);
    }

    public function test_falls_back_to_market_prices_average_price(): void
    {
        MarketPrice::create([
            'type_id' => 100,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'average_price' => 999,
        ]);

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(999.0, $result[100]);
    }

    public function test_returns_zero_when_no_data_at_all(): void
    {
        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(0, $result[100]);
    }

    public function test_handles_multiple_types(): void
    {
        $this->createSellOrder(100, 500, 1000);
        $this->createSellOrder(200, 1000, 1000);

        MarketPrice::create([
            'type_id' => 300,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'sell_min' => 250,
        ]);

        $result = $this->marketService->getSellPrices([100, 200, 300]);

        $this->assertEquals(500.0, $result[100]);
        $this->assertEquals(1000.0, $result[200]);
        $this->assertEquals(250.0, $result[300]);
    }

    public function test_ignores_orders_beyond_max_days(): void
    {
        // EVE orders last at most 90 days, so anything older has expired
        $oldDate = now()->subDays(MarketService::MAX_ORDER_AGE_DAYS + 10)->toDateTimeString();
        $this->createSellOrder(100, 500, 1000, $oldDate);

        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(0, $result[100]);
    }

    public function test_uses_valid_orders_older_than_thirty_days(): void
    {
        // A 90-day order issued 40 days ago is still on the market
        $this->createSellOrder(100, 500, 1000, now()->subDays(40)->toDateTimeString());

        $result = $this->marketService->getSellPricesWithSource([100]);

        $this->assertEquals(500.0, $result[100]['price']);
        $this->assertEquals(MarketService::PRICE_SOURCE_ORDERS, $result[100]['source']);
    }

    public function test_non_jita_region_falls_back_to_jita_price_instead_of_zero(): void
    {
        $amarr = 10000043;

        // Only Jita has a market_prices row (global prices are stored there)
        MarketPrice::create([
            'type_id' => 100,
            'region_id' => MarketService::DEFAULT_REGION_ID,
            'average_price' => 812,
        ]);

        $result = $this->marketService->getSellPricesWithSource([100], $amarr);

        $this->assertEquals(812.0, $result[100]['price']);
        $this->assertEquals(MarketService::PRICE_SOURCE_FALLBACK, $result[100]['source']);
        $this->assertEquals(812.0, $this->marketService->getSellPrices([100], $amarr)[100]);
    }

    public function test_region_price_row_is_preferred_over_jita_fallback(): void
    {
        $amarr = 10000043;

        MarketPrice::create(['type_id' => 100, 'region_id' => $amarr, 'sell_min' => 640]);
        MarketPrice::create(['type_id' => 100, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 600]);

        $result = $this->marketService->getSellPrices([100], $amarr);

        $this->assertEquals(640.0, $result[100]);
    }

    public function test_empty_region_price_row_falls_through_to_jita(): void
    {
        $amarr = 10000043;

        // A region row with no usable price must not pin the price at 0
        MarketPrice::create(['type_id' => 100, 'region_id' => $amarr, 'sell_min' => null, 'average_price' => null]);
        MarketPrice::create(['type_id' => 100, 'region_id' => MarketService::DEFAULT_REGION_ID, 'average_price' => 700]);

        $result = $this->marketService->getSellPrices([100], $amarr);

        $this->assertEquals(700.0, $result[100]);
    }

    public function test_reports_none_source_when_no_data_at_all(): void
    {
        $result = $this->marketService->getSellPricesWithSource([100], 10000043);

        $this->assertEquals(0.0, $result[100]['price']);
        $this->assertEquals(MarketService::PRICE_SOURCE_NONE, $result[100]['source']);
    }

    public function test_uses_orders_from_any_recent_date(): void
    {
        // Orders from different days — all should be included in the 5% calc
        $this->createSellOrder(100, 100, 30, now()->subDays(5)->toDateTimeString());
        $this->createSellOrder(100, 200, 30, now()->subDays(2)->toDateTimeString());
        $this->createSellOrder(100, 500, 940, now()->toDateTimeString());

        // Total = 1000, 5% = 50 units
        // Sorted: 100@30, 200@30, 500@940 → (100*30 + 200*20)/50 = 140
        $result = $this->marketService->getSellPrices([100]);

        $this->assertEquals(140.0, $result[100]);
    }

    private function completeSweep(string $sweepId): void
    {
        $regionId = MarketService::DEFAULT_REGION_ID;
        $state = \App\Models\MarketRegionState::firstOrCreate(['region_id' => $regionId]);
        $generation = $state->latest_generation + 1;

        app(\App\Services\MarketAggregateService::class)->rebuild($regionId, $sweepId, now());
        \App\Models\MarketOrderSweep::create([
            'sweep_id' => $sweepId, 'region_id' => $regionId, 'generation' => $generation,
            'status' => \App\Models\MarketOrderSweep::STATUS_COMPLETED,
            'started_at' => now(), 'finished_at' => now(), 'duration_seconds' => 60,
        ]);
        $state->update([
            'latest_generation' => $generation,
            'latest_sweep_id' => $sweepId,
            'latest_started_at' => now(),
        ]);
        // As MarketOrderSweepService::complete() does: point readers at the new snapshot.
        app(\App\Services\MarketAggregateService::class)->publishSnapshot($regionId, $sweepId, now());
    }

    public function test_after_a_completed_sweep_prices_come_from_the_aggregates_with_the_same_value(): void
    {
        $this->createSellOrder(100, 100, 30);
        $this->createSellOrder(100, 200, 30);
        $this->createSellOrder(100, 500, 940);
        $fromOrders = $this->marketService->getSellPricesWithSource([100, 200]);

        $this->completeSweep('sweep-1');
        // Raw orders written after the snapshot (a sweep in progress) are not read.
        $this->createSellOrder(100, 1, 1000000);

        $fromAggregates = $this->marketService->getSellPricesWithSource([100, 200]);

        $this->assertSame($fromOrders, $fromAggregates);
        $this->assertSame(['price' => 140.0, 'source' => MarketService::PRICE_SOURCE_ORDERS], $fromAggregates[100]);
    }

    public function test_type_missing_from_the_snapshot_still_falls_back_to_market_prices(): void
    {
        $this->createSellOrder(100, 500);
        $this->completeSweep('sweep-1');
        MarketPrice::create(['type_id' => 200, 'region_id' => MarketService::DEFAULT_REGION_ID, 'sell_min' => 42.0]);

        $result = $this->marketService->getSellPricesWithSource([100, 200]);

        $this->assertSame(['price' => 500.0, 'source' => MarketService::PRICE_SOURCE_ORDERS], $result[100]);
        $this->assertSame(['price' => 42.0, 'source' => MarketService::PRICE_SOURCE_FALLBACK], $result[200]);
    }

    public function test_non_default_window_still_reads_raw_orders(): void
    {
        $this->createSellOrder(100, 500);
        $this->completeSweep('sweep-1');
        $this->createSellOrder(100, 100);

        // Both orders, equal volume: the cheapest 5% is all at 100.
        $this->assertEquals(100.0, $this->marketService->getSellPrices([100], MarketService::DEFAULT_REGION_ID, 30)[100]);
    }
}
