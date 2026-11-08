<?php

namespace Tests\Unit;

use App\Jobs\FetchRegionOrderPages;
use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\MarketOrder;
use App\Models\MarketOrderSweep;
use App\Services\EsiService;
use App\Services\MarketOrderSweepService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchRegionOrderPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    public function test_job_inserts_orders_into_database(): void
    {
        Http::fake([
            '*/markets/*/orders/*' => Http::response([
                [
                    'order_id' => 100,
                    'type_id' => 34,
                    'is_buy_order' => false,
                    'price' => 5.50,
                    'volume_remain' => 1000,
                    'volume_total' => 5000,
                    'min_volume' => 1,
                    'location_id' => 60003760,
                    'duration' => 90,
                    'range' => 'station',
                    'issued' => '2026-01-15T10:00:00Z',
                ],
                [
                    'order_id' => 101,
                    'type_id' => 34,
                    'is_buy_order' => true,
                    'price' => 4.80,
                    'volume_remain' => 2000,
                    'volume_total' => 2000,
                    'min_volume' => 1,
                    'location_id' => 60003760,
                    'duration' => 30,
                    'range' => 'region',
                    'issued' => '2026-01-15T11:00:00Z',
                ],
            ], 200, ['X-Pages' => '1']),
        ]);

        $batchId = app(MarketOrderSweepService::class)->start(10000002)->sweep_id;
        $job = new FetchRegionOrderPages(10000002, 1, $batchId);
        $job->handle(new EsiService);

        $this->assertDatabaseHas('market_orders', [
            'order_id' => 100,
            'region_id' => 10000002,
            'type_id' => 34,
            'is_buy_order' => false,
            'price' => 5.50,
            'batch_id' => $batchId,
        ]);

        $this->assertDatabaseHas('market_orders', [
            'order_id' => 101,
            'is_buy_order' => true,
            'price' => 4.80,
            'batch_id' => $batchId,
        ]);
    }

    public function test_job_fetches_single_page(): void
    {
        Http::fake([
            '*/markets/*/orders/*' => Http::response([
                [
                    'order_id' => 1001,
                    'type_id' => 35,
                    'is_buy_order' => false,
                    'price' => 11.0,
                    'volume_remain' => 100,
                    'volume_total' => 100,
                ],
            ], 200, ['X-Pages' => '10']),
        ]);

        $batchId = app(MarketOrderSweepService::class)->start(10000002)->sweep_id;
        $job = new FetchRegionOrderPages(10000002, 3, $batchId);
        $job->handle(new EsiService);

        // Exactly one HTTP request for the single page
        Http::assertSentCount(1);
        $this->assertEquals(1, MarketOrder::where('batch_id', $batchId)->count());
    }

    public function test_job_upserts_existing_orders(): void
    {
        MarketOrder::create([
            'order_id' => 200,
            'region_id' => 10000002,
            'type_id' => 34,
            'is_buy_order' => false,
            'price' => 5.00,
            'volume_remain' => 500,
            'volume_total' => 1000,
            'batch_id' => 'old-batch',
        ]);

        Http::fake([
            '*/markets/*/orders/*' => Http::response([
                [
                    'order_id' => 200,
                    'type_id' => 34,
                    'is_buy_order' => false,
                    'price' => 6.00,
                    'volume_remain' => 300,
                    'volume_total' => 1000,
                ],
            ], 200, ['X-Pages' => '1']),
        ]);

        $batchId = app(MarketOrderSweepService::class)->start(10000002)->sweep_id;
        $job = new FetchRegionOrderPages(10000002, 1, $batchId);
        $job->handle(new EsiService);

        // Only one record — upserted, not duplicated
        $this->assertEquals(1, MarketOrder::count());

        $order = MarketOrder::find(200);
        $this->assertEquals(6.00, $order->price);
        $this->assertEquals(300, $order->volume_remain);
        $this->assertEquals($batchId, $order->batch_id);
    }

    public function test_upsert_succeeds_on_normal_run(): void
    {
        Http::fake([
            '*/markets/*/orders/*' => Http::response([
                [
                    'order_id' => 600,
                    'type_id' => 34,
                    'is_buy_order' => false,
                    'price' => 1.00,
                    'volume_remain' => 1,
                    'volume_total' => 1,
                ],
            ], 200, ['X-Pages' => '1']),
        ]);

        MarketOrder::create([
            'order_id' => 600,
            'region_id' => 10000002,
            'type_id' => 34,
            'is_buy_order' => false,
            'price' => 0.50,
            'volume_remain' => 1,
            'volume_total' => 1,
            'batch_id' => 'old',
        ]);

        $batchId = app(MarketOrderSweepService::class)->start(10000002)->sweep_id;
        $job = new FetchRegionOrderPages(10000002, 1, $batchId);
        $job->handle(new EsiService);

        $this->assertDatabaseHas('market_orders', [
            'order_id' => 600,
            'price' => 1.00,
            'batch_id' => $batchId,
        ]);
    }

    /**
     * Previously a failed page returned quietly, counted as a success, and
     * the sweep's cleanup then deleted that page's orders. It now throws so
     * the queue retries it and, if it keeps failing, the sweep fails.
     */
    public function test_failed_page_throws_and_stores_nothing(): void
    {
        Http::fake([
            '*/markets/*/orders/*' => Http::response(null, 500),
        ]);

        $job = new FetchRegionOrderPages(10000002, 1, 'batch-fail');

        try {
            $job->handle(new EsiService);
            $this->fail('A failed page must throw so the queue retries it.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('page 1 failed', $e->getMessage());
        }

        $this->assertEquals(0, MarketOrder::where('batch_id', 'batch-fail')->count());
    }

    public function test_job_is_batchable_and_runs_on_the_market_queue_with_retries(): void
    {
        $job = new FetchRegionOrderPages(10000002, 1, 'batch-id');

        $this->assertContains(\Illuminate\Bus\Batchable::class, class_uses_recursive($job));
        $this->assertSame('market', $job->queue);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertGreaterThan($job->maxExceptions, $job->tries);
    }

    public function test_sends_cached_etag_and_on_304_marks_the_cached_orders_seen(): void
    {
        Queue::fake([PrecomputeManufacturingCostsChunk::class]);
        $this->travelTo(now()->startOfMinute());
        $sweeps = app(MarketOrderSweepService::class);
        $first = $sweeps->start(10000002);

        Http::fake(['*/markets/*/orders/*' => Http::sequence()
            ->push([['order_id' => 7, 'type_id' => 34, 'is_buy_order' => false, 'price' => 1.0, 'volume_remain' => 1, 'volume_total' => 1]], 200, ['X-Pages' => '2', 'ETag' => '"v1"'])
            ->push('', 304, ['ETag' => '"v1"', 'X-Pages' => '2']),
        ]);

        (new FetchRegionOrderPages(10000002, 2, $first->sweep_id))->handle(new EsiService);
        $sweeps->complete($first->sweep_id);

        $this->travel(15)->minutes();
        $second = $sweeps->start(10000002);
        (new FetchRegionOrderPages(10000002, 2, $second->sweep_id))->handle(new EsiService);

        $recorded = Http::recorded();
        $this->assertFalse($recorded[0][0]->hasHeader('If-None-Match'));
        $this->assertSame(['"v1"'], $recorded[1][0]->header('If-None-Match'));

        $order = MarketOrder::find(7);
        $this->assertSame($second->sweep_id, $order->batch_id);
        $this->assertSame(now()->toDateTimeString(), $order->updated_at->toDateTimeString());
        $this->assertSame(1, MarketOrderSweep::where('sweep_id', $second->sweep_id)->value('pages_not_modified'));
    }

    public function test_releases_instead_of_failing_while_the_esi_error_budget_is_exhausted(): void
    {
        $this->freezeTime();
        Cache::put('esi.error_circuit.until', now()->timestamp + 60, 60);
        $this->travel(15)->seconds();
        Http::fake();

        $job = (new FetchRegionOrderPages(10000002, 1, 'b'))->withFakeQueueInteractions();
        $job->handle(new EsiService);

        $job->assertReleased(45);
        Http::assertNothingSent();
        $this->assertSame(0, MarketOrder::count());
    }
}
