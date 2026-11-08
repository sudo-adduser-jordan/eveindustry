<?php

namespace App\Console\Commands;

use App\Jobs\FetchRegionHistory as FetchRegionHistoryJob;
use App\Services\MarketTradingService;
use Illuminate\Console\Command;

class FetchRegionHistory extends Command
{
    protected $signature = 'market:fetch-region-history
        {--region= : Fetch history for a single region ID instead of all trading hubs}';

    protected $description = 'Fetch and store market history for every traded type in each trading-hub region';

    public function handle(): int
    {
        if ($regionId = $this->option('region')) {
            $this->info("Dispatching history fetch for region {$regionId}...");
            FetchRegionHistoryJob::dispatch((int) $regionId);
            $this->info('Job dispatched.');

            return self::SUCCESS;
        }

        foreach (MarketTradingService::REGIONS as $id => $name) {
            $this->info("Dispatching history fetch for {$name} ({$id})...");
            FetchRegionHistoryJob::dispatch($id);
        }

        $this->info('All region history fetch jobs dispatched.');

        return self::SUCCESS;
    }
}
