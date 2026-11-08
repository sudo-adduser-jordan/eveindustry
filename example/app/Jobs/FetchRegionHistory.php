<?php

namespace App\Jobs;

use App\Models\MarketHistory;
use App\Models\MarketOrder;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class FetchRegionHistory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** Number of type IDs per child job. */
    public const TYPES_PER_CHUNK = 25;

    /**
     * @param  int  $regionId  The region to fetch history for
     */
    public function __construct(
        protected int $regionId,
    ) {
        $this->onQueue('updates');
    }

    public function handle(): void
    {
        Log::info("FetchRegionHistory: discovering traded type_ids for region {$this->regionId}");

        // Get all distinct type_ids that have orders in this region
        $typeIds = MarketOrder::where('region_id', $this->regionId)
            ->distinct()
            ->pluck('type_id')
            ->all();

        if (empty($typeIds)) {
            Log::warning("FetchRegionHistory: no market orders found for region {$this->regionId}, nothing to fetch");

            return;
        }

        Log::info('FetchRegionHistory: found '.count($typeIds)." traded types for region {$this->regionId}");

        // Split type_ids into chunks and create a child job for each chunk
        $chunks = array_chunk($typeIds, self::TYPES_PER_CHUNK);
        $jobs = [];

        foreach ($chunks as $chunk) {
            $jobs[] = new FetchRegionHistoryChunk($this->regionId, $chunk);
        }

        $regionId = $this->regionId;
        Bus::batch($jobs)
            ->name("fetch-region-history-{$regionId}")
            ->onQueue('updates')
            ->allowFailures()
            ->then(function () use ($regionId, $typeIds) {
                Log::info("FetchRegionHistory: batch complete for region {$regionId}, fetched history for ".count($typeIds).' types');
            })
            ->catch(function ($batch, $e) use ($regionId) {
                Log::error("FetchRegionHistory: batch failed for region {$regionId}: {$e->getMessage()}");
            })
            ->finally(function () use ($regionId) {
                Log::info("FetchRegionHistory: batch finished for region {$regionId}");
                MarketHistory::where('date', '<', Carbon::now()->subDays(30))->where('region_id', $regionId)->delete();
            })
            ->dispatch();

        Log::info('FetchRegionHistory: dispatched '.count($jobs)." chunk jobs for region {$regionId}");
    }
}
