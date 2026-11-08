<?php

namespace App\Jobs;

use App\Services\Esi\RegionOrdersPage;
use App\Services\EsiService;
use App\Services\MarketOrderSweepService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Fetches one page of a region order sweep through EsiService (ETag aware)
 * and stores it. A page that cannot be fetched throws, so the queue retries
 * it; once it has failed $maxExceptions times the job fails, the batch is
 * cancelled and the sweep deletes nothing.
 */
class FetchRegionOrderPages implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Attempts in total, including releases for rate limiting and the ESI
     * error budget, which are not failures.
     */
    public int $tries = 10;

    /** Real failures (HTTP errors, timeouts) allowed before the job fails. */
    public int $maxExceptions = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  int  $regionId  The region to fetch orders for
     * @param  int  $page  Page number to fetch
     * @param  string  $fetchBatchId  The sweep id (market_order_sweeps.sweep_id); also stamped on each order as batch_id
     */
    public function __construct(
        protected int $regionId,
        protected int $page,
        protected string $fetchBatchId,
    ) {
        $this->onQueue('market');
    }

    public function handle(EsiService $esi, ?MarketOrderSweepService $sweeps = null): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $sweeps ??= app(MarketOrderSweepService::class);

        if ($esi->isEsiErrorLimited()) {
            Log::warning("FetchRegionOrderPages: ESI error budget exhausted, releasing region {$this->regionId} page {$this->page}");
            $this->release($esi->esiErrorResetSeconds() ?: 30);

            return;
        }

        $cached = $sweeps->cachedPage($this->regionId, $this->page);
        $result = $esi->fetchRegionOrdersPage($this->regionId, $this->page, $cached['etag'] ?? null);

        switch ($result->status) {
            case RegionOrdersPage::RATE_LIMITED:
                $this->release($result->retryAfter);

                return;

            case RegionOrdersPage::NOT_MODIFIED:
                $count = $sweeps->markPageUnchanged($this->fetchBatchId, $this->regionId, $this->page, $cached);
                Log::debug("FetchRegionOrderPages: region {$this->regionId} page {$this->page} unchanged ({$count} orders)");

                return;

            case RegionOrdersPage::MISSING:
                $sweeps->markPageMissing($this->fetchBatchId, $this->regionId, $this->page);
                Log::info("FetchRegionOrderPages: region {$this->regionId} page {$this->page} no longer exists");

                return;

            case RegionOrdersPage::OK:
                $count = $sweeps->storePage($this->fetchBatchId, $this->regionId, $this->page, $result);
                Log::debug("FetchRegionOrderPages: region {$this->regionId} page {$this->page} stored {$count} orders");

                return;

            default:
                throw new \RuntimeException("FetchRegionOrderPages: region {$this->regionId} page {$this->page} failed (HTTP ".($result->httpStatus ?? 'none').')');
        }
    }
}
