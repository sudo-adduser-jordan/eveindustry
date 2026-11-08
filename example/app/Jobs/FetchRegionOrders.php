<?php

namespace App\Jobs;

use App\Services\Esi\RegionOrdersPage;
use App\Services\EsiService;
use App\Services\MarketOrderSweepService;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

/**
 * Starts one region order sweep: fetches page 1 (which reports the page
 * count in X-Pages), stores it, then batches one FetchRegionOrderPages job
 * per remaining page. When the batch finishes, CompleteMarketOrderSweep
 * either completes the sweep (every page arrived) or fails it (deletes
 * nothing).
 */
class FetchRegionOrders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Attempts in total, including releases for rate limiting. */
    public int $tries = 5;

    /** Real failures fetching page 1 allowed before the job fails. */
    public int $maxExceptions = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    /** Number of ESI pages to fetch per child job. */
    public const PAGES_PER_CHUNK = 1;

    /**
     * @param  int  $regionId  The region to fetch orders for
     */
    public function __construct(
        protected int $regionId,
    ) {
        $this->onQueue('market');
    }

    public function handle(EsiService $esi, ?MarketOrderSweepService $sweeps = null): void
    {
        $sweeps ??= app(MarketOrderSweepService::class);

        if ($esi->isEsiErrorLimited()) {
            Log::warning("FetchRegionOrders: ESI error budget exhausted, releasing region {$this->regionId}");
            $this->release($esi->esiErrorResetSeconds() ?: 30);

            return;
        }

        // Taken before page 1 is fetched: anything this sweep stores is
        // stamped at or after this time, which is what cleanup relies on.
        $startedAt = now();

        $cached = $sweeps->cachedPage($this->regionId, 1);
        $first = $esi->fetchRegionOrdersPage($this->regionId, 1, $cached['etag'] ?? null);

        if ($first->status === RegionOrdersPage::RATE_LIMITED) {
            Log::info("FetchRegionOrders: rate-limited, releasing region {$this->regionId} for {$first->retryAfter}s");
            $this->release($first->retryAfter);

            return;
        }

        if (! $first->isSuccessful() || $first->status === RegionOrdersPage::MISSING) {
            // No sweep has started, so nothing is deleted; the queue retries.
            throw new \RuntimeException("FetchRegionOrders: page 1 failed for region {$this->regionId} (HTTP ".($first->httpStatus ?? 'none').')');
        }

        $sweep = $sweeps->start($this->regionId, $startedAt);
        if ($sweep === null) {
            Log::info("FetchRegionOrders: a sweep is already running for region {$this->regionId}, skipping");

            return;
        }

        $sweepId = $sweep->sweep_id;

        if ($first->status === RegionOrdersPage::NOT_MODIFIED) {
            $sweeps->markPageUnchanged($sweepId, $this->regionId, 1, $cached);
            $totalPages = $first->pages ?? $cached['pages'] ?? 1;
        } else {
            $sweeps->storePage($sweepId, $this->regionId, 1, $first);
            $totalPages = $first->pages ?? 1;
        }

        $sweeps->setPagesTotal($sweepId, $totalPages);
        Log::info("FetchRegionOrders: region {$this->regionId} has {$totalPages} pages (sweep {$sweepId})");

        if ($totalPages <= 1) {
            CompleteMarketOrderSweep::dispatch($sweepId, true);

            return;
        }

        $jobs = [];
        for ($page = 2; $page <= $totalPages; $page++) {
            $jobs[] = new FetchRegionOrderPages($this->regionId, $page, $sweepId);
        }

        // No allowFailures(): the first page that fails for good cancels the
        // rest, since the sweep can no longer complete.
        Bus::batch($jobs)
            ->name("fetch-region-orders-{$this->regionId}")
            ->onQueue('market')
            ->finally(function (Batch $batch) use ($sweepId) {
                FetchRegionOrders::onBatchFinished($sweepId, $batch);
            })
            ->dispatch();

        Log::info('FetchRegionOrders: dispatched '.count($jobs)." page jobs for region {$this->regionId} (sweep {$sweepId})");
    }

    /**
     * Runs once every page job has run: complete the sweep only when none
     * failed and the batch was not cancelled.
     */
    public static function onBatchFinished(string $sweepId, Batch $batch): void
    {
        CompleteMarketOrderSweep::dispatch($sweepId, $batch->failedJobs === 0 && ! $batch->cancelled());
    }
}
