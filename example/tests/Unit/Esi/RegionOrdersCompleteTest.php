<?php

namespace Tests\Unit\Esi;

use App\Jobs\FetchRegionOrders;
use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\MarketAggregate;
use App\Models\MarketOrder;
use App\Models\MarketOrderSweep;
use App\Services\EsiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Region arbitrage reads market_orders through EsiService; it must see every
 * order in the region, and the job that fills the table must fetch every
 * page ESI reports in X-Pages.
 */
class RegionOrdersCompleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        config(['queue.default' => 'sync']);
        Queue::fake([PrecomputeManufacturingCostsChunk::class]);
    }

    public function test_get_market_region_all_orders_returns_more_than_500_orders(): void
    {
        $rows = [];
        for ($i = 1; $i <= 1203; $i++) {
            $rows[] = [
                'order_id' => $i,
                'region_id' => 10000002,
                'type_id' => 34,
                'is_buy_order' => $i % 2 === 0,
                'price' => $i + 0.5,
                'volume_remain' => 10,
                'volume_total' => 10,
                'min_volume' => 1,
                'issued' => '2026-01-01 00:00:00',
                'batch_id' => 'b',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            MarketOrder::insert($chunk);
        }
        // An order in another region must not leak in.
        MarketOrder::insert([array_merge($rows[0], ['order_id' => 999999, 'region_id' => 10000043])]);

        $result = (new EsiService)->getMarketRegionAllOrders(10000002);

        $this->assertCount(1203, $result);
        $this->assertSame(range(1, 1203), array_column($result, 'order_id'));
        $this->assertEqualsCanonicalizing(
            ['order_id', 'type_id', 'is_buy_order', 'price', 'volume_remain', 'volume_total', 'min_volume', 'location_id', 'duration', 'range', 'issued'],
            array_keys($result[0])
        );
    }

    public function test_fetch_region_orders_fetches_and_stores_every_page(): void
    {
        $regionId = 10000002;

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);

            // Three pages, two different orders on each, placed yesterday
            // (aggregates, like pricing, skip orders older than 90 days).
            $issued = now()->subDay()->toIso8601ZuluString();
            $orders = [
                ['order_id' => $page * 10 + 1, 'type_id' => 34, 'is_buy_order' => false, 'price' => 5.0 + $page, 'volume_remain' => 10, 'volume_total' => 10, 'min_volume' => 1, 'location_id' => 60003760, 'duration' => 90, 'range' => 'region', 'issued' => $issued],
                ['order_id' => $page * 10 + 2, 'type_id' => 35, 'is_buy_order' => true, 'price' => 3.0 + $page, 'volume_remain' => 20, 'volume_total' => 20, 'min_volume' => 1, 'location_id' => 60003760, 'duration' => 90, 'range' => 'region', 'issued' => $issued],
            ];

            return Http::response($orders, 200, ['X-Pages' => '3']);
        });

        // An order from an earlier sweep that ESI no longer lists.
        MarketOrder::insert([[
            'order_id' => 999, 'region_id' => $regionId, 'type_id' => 34, 'is_buy_order' => false, 'price' => 1.0,
            'volume_remain' => 1, 'volume_total' => 1, 'min_volume' => 1, 'issued' => '2026-01-01 00:00:00',
            'batch_id' => 'old', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]]);

        (new FetchRegionOrders($regionId))->handle(new EsiService);

        $requestedPages = [];
        Http::assertSent(function (Request $request) use (&$requestedPages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $requestedPages[] = (int) $query['page'];

            return true;
        });
        // Page 1 is fetched once (it reports X-Pages and is stored), then one job per other page.
        $this->assertEqualsCanonicalizing([1, 2, 3], $requestedPages);

        // Every page arrived, so the sweep completed and removed the unlisted order.
        $stored = MarketOrder::where('region_id', $regionId)->orderBy('order_id')->pluck('order_id')->all();
        $this->assertSame([11, 12, 21, 22, 31, 32], $stored);

        $sweep = MarketOrderSweep::sole();
        $this->assertSame(MarketOrderSweep::STATUS_COMPLETED, $sweep->status);
        $this->assertSame(3, $sweep->pages_fetched);
        $this->assertSame(1, $sweep->orders_deleted);
        $this->assertSame(2, MarketAggregate::where('region_id', $regionId)->count());
    }

    public function test_a_page_that_keeps_failing_fails_the_sweep_and_deletes_nothing(): void
    {
        $regionId = 10000002;

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 1);

            if ($page === 2) {
                return Http::response(null, 502);
            }

            return Http::response([
                ['order_id' => $page * 10 + 1, 'type_id' => 34, 'is_buy_order' => false, 'price' => 5.0, 'volume_remain' => 10, 'volume_total' => 10, 'issued' => '2026-01-01T00:00:00Z'],
            ], 200, ['X-Pages' => '3']);
        });

        MarketOrder::insert([[
            'order_id' => 999, 'region_id' => $regionId, 'type_id' => 34, 'is_buy_order' => false, 'price' => 1.0,
            'volume_remain' => 1, 'volume_total' => 1, 'min_volume' => 1, 'issued' => '2026-01-01 00:00:00',
            'batch_id' => 'old', 'created_at' => now()->subHour(), 'updated_at' => now()->subHour(),
        ]]);

        try {
            // The sync queue runs the batch inline and rethrows the page
            // failure. On a real queue the page is retried, then the batch
            // is cancelled and its finally() fails the sweep (see
            // FetchRegionOrdersJobTest::test_batch_finished_completes_the_sweep_only_when_no_page_failed).
            (new FetchRegionOrders($regionId))->handle(new EsiService);
            $this->fail('The failing page should surface.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('page 2 failed', $e->getMessage());
        }

        $sweep = MarketOrderSweep::sole();
        $this->assertNotSame(MarketOrderSweep::STATUS_COMPLETED, $sweep->status);
        // Page 2's orders were never fetched, so nothing may be deleted.
        $this->assertNotNull(MarketOrder::find(999));
        $this->assertSame(0, MarketAggregate::count());
    }
}
