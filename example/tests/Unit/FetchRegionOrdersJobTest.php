<?php

namespace Tests\Unit;

use App\Jobs\CompleteMarketOrderSweep;
use App\Jobs\FetchRegionOrderPages;
use App\Jobs\FetchRegionOrders;
use App\Models\MarketOrder;
use App\Models\MarketOrderSweep;
use App\Services\EsiService;
use App\Services\MarketOrderSweepService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FetchRegionOrdersJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
    }

    public function test_job_stores_page_one_starts_a_sweep_and_batches_the_remaining_pages(): void
    {
        Bus::fake([FetchRegionOrderPages::class]);

        $regionId = 10000002;

        Http::fake([
            '*/markets/*/orders/*' => Http::response(
                [['order_id' => 1, 'price' => 10.0, 'type_id' => 34, 'volume_remain' => 1, 'volume_total' => 1]],
                200,
                ['X-Pages' => '3']
            ),
        ]);

        $job = new FetchRegionOrders($regionId);
        $job->handle(new EsiService);

        // Page 1 is fetched once, stored here, and not fetched again.
        Http::assertSentCount(1);
        $sweep = MarketOrderSweep::sole();
        $this->assertSame(MarketOrderSweep::STATUS_RUNNING, $sweep->status);
        $this->assertSame(3, $sweep->pages_total);
        $this->assertSame(1, $sweep->pages_fetched);
        $this->assertSame($sweep->sweep_id, MarketOrder::find(1)->batch_id);

        Bus::assertBatched(function ($batch) {
            // Pages 2 and 3, one job each
            return $batch->jobs->count() === 2
                && $batch->jobs->first() instanceof FetchRegionOrderPages
                && $batch->queue() === 'market';
        });
    }

    public function test_job_dispatches_one_page_job_per_remaining_page(): void
    {
        Bus::fake([FetchRegionOrderPages::class]);

        Http::fake([
            '*/markets/*/orders/*' => Http::response(
                [['order_id' => 1, 'price' => 10.0, 'type_id' => 34]],
                200,
                ['X-Pages' => '75']
            ),
        ]);

        $job = new FetchRegionOrders(10000002);
        $job->handle(new EsiService);

        // 75 pages: page 1 here, 74 page jobs
        Bus::assertBatched(function ($batch) {
            return $batch->jobs->count() === 74;
        });
    }

    public function test_single_page_region_completes_without_a_batch(): void
    {
        Bus::fake([FetchRegionOrderPages::class, CompleteMarketOrderSweep::class]);
        Http::fake(['*' => Http::response([], 200, ['X-Pages' => '1'])]);

        (new FetchRegionOrders(10000043))->handle(new EsiService);

        Bus::assertNothingBatched();
        Bus::assertDispatched(CompleteMarketOrderSweep::class);
    }

    /**
     * Previously a failed page 1 returned quietly. It now throws so the queue
     * retries it; no sweep is started, so nothing can be deleted.
     */
    public function test_job_throws_and_starts_no_sweep_when_page_one_fails(): void
    {
        Bus::fake([FetchRegionOrderPages::class]);

        Http::fake([
            '*/markets/*/orders/*' => Http::response(null, 503),
        ]);

        try {
            (new FetchRegionOrders(10000002))->handle(new EsiService);
            $this->fail('A failed page 1 must throw so the queue retries it.');
        } catch (\RuntimeException) {
        }

        Bus::assertNothingBatched();
        $this->assertSame(0, MarketOrderSweep::count());
    }

    public function test_job_skips_a_region_whose_sweep_is_still_running(): void
    {
        Bus::fake([FetchRegionOrderPages::class]);
        Http::fake(['*' => Http::response([['order_id' => 9, 'type_id' => 34, 'price' => 1.0]], 200, ['X-Pages' => '3'])]);

        $running = app(MarketOrderSweepService::class)->start(10000002);

        (new FetchRegionOrders(10000002))->handle(new EsiService);

        Bus::assertNothingBatched();
        $this->assertSame(1, MarketOrderSweep::count());
        // The fetched page was not stored under a second sweep.
        $this->assertNull(MarketOrder::find(9));
        $this->assertSame(MarketOrderSweep::STATUS_RUNNING, $running->fresh()->status);
    }

    public function test_job_is_queueable_on_the_market_queue(): void
    {
        $job = new FetchRegionOrders(10000002);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $job);
        $this->assertSame('market', $job->queue);
    }

    public function test_pages_per_chunk_constant(): void
    {
        $this->assertEquals(1, FetchRegionOrders::PAGES_PER_CHUNK);
    }

    public function test_batch_finished_completes_the_sweep_only_when_no_page_failed(): void
    {
        Queue::fake([CompleteMarketOrderSweep::class]);

        $batch = fn (int $failed, bool $cancelled) => new Batch(
            app(\Illuminate\Contracts\Queue\Factory::class), app(BatchRepository::class), 'id', 'name', 10, 0, $failed, [], [],
            now()->toImmutable(), $cancelled ? now()->toImmutable() : null,
        );

        FetchRegionOrders::onBatchFinished('ok', $batch(0, false));
        FetchRegionOrders::onBatchFinished('failed-page', $batch(1, true));
        FetchRegionOrders::onBatchFinished('cancelled', $batch(0, true));

        $pushed = [];
        Queue::assertPushed(CompleteMarketOrderSweep::class, function (CompleteMarketOrderSweep $job) use (&$pushed) {
            $read = fn (string $prop) => (new \ReflectionProperty($job, $prop))->getValue($job);
            $pushed[$read('sweepId')] = $read('allPagesArrived');

            return true;
        });

        $this->assertSame(['ok' => true, 'failed-page' => false, 'cancelled' => false], $pushed);
    }

    public function test_stale_order_delete_retries_on_deadlock(): void
    {
        // Simulate the then() callback directly: it should retry on deadlock
        // (MySQL error 1213) and succeed on a subsequent attempt.
        $regionId = 10000002;
        $fetchBatchId = 'current-batch';
        $staleBatchId = 'old-batch';

        MarketOrder::create([
            'order_id' => 1,
            'region_id' => $regionId,
            'type_id' => 34,
            'is_buy_order' => false,
            'price' => 5.00,
            'volume_remain' => 1,
            'volume_total' => 1,
            'batch_id' => $staleBatchId,
        ]);

        MarketOrder::create([
            'order_id' => 2,
            'region_id' => $regionId,
            'type_id' => 34,
            'is_buy_order' => false,
            'price' => 5.00,
            'volume_remain' => 1,
            'volume_total' => 1,
            'batch_id' => $fetchBatchId,
        ]);

        // Build a fake deadlock exception (MySQL 1213)
        $deadlockException = new QueryException(
            'mysql',
            'delete from `market_orders` where `region_id` = ? and `batch_id` != ?',
            [],
            new \Exception('Deadlock found when trying to get lock', 1213)
        );
        // Set errorInfo via reflection so the deadlock predicate matches
        $prop = new \ReflectionProperty(QueryException::class, 'errorInfo');
        $prop->setValue($deadlockException, ['HY000', 1213, 'Deadlock found when trying to get lock']);

        $attempts = 0;

        $isDeadlock = fn ($e) => $e instanceof QueryException
            && (($e->errorInfo[1] ?? null) === 1213);

        $deleted = retry(3, function () use (&$attempts, $deadlockException, $regionId, $fetchBatchId) {
            $attempts++;
            if ($attempts === 1) {
                throw $deadlockException;
            }

            return MarketOrder::where('region_id', $regionId)
                ->where('batch_id', '!=', $fetchBatchId)
                ->delete();
        }, fn ($attempt) => 0 /* no sleep in tests */, $isDeadlock);

        $this->assertEquals(2, $attempts, 'Should have retried once after the deadlock');
        $this->assertEquals(1, $deleted, 'Stale order should have been deleted');
        $this->assertDatabaseMissing('market_orders', ['batch_id' => $staleBatchId]);
        $this->assertDatabaseHas('market_orders', ['batch_id' => $fetchBatchId]);
    }
}
