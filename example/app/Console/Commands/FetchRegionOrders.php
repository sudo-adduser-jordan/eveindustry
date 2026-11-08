<?php

namespace App\Console\Commands;

use App\Jobs\FetchRegionOrders as FetchRegionOrdersJob;
use App\Services\MarketTradingService;
use Illuminate\Console\Command;

/**
 * Starts a region order sweep (FetchRegionOrders on the "market" queue) for
 * each selected trade hub. routes/console.php runs Jita on its own gated
 * 15-minute entry and the other hubs hourly with --exclude.
 */
class FetchRegionOrders extends Command
{
    protected $signature = 'market:fetch-region-orders
        {--region= : Fetch orders for a single region ID instead of all trading hubs}
        {--exclude=* : Region IDs to leave out when fetching all trading hubs}';

    protected $description = 'Sweep every market order in each trading-hub region from ESI';

    public function handle(): int
    {
        if ($regionId = $this->option('region')) {
            $this->info("Dispatching order sweep for region {$regionId}...");
            FetchRegionOrdersJob::dispatch((int) $regionId);
            $this->info('Job dispatched.');

            return self::SUCCESS;
        }

        $excluded = array_map('intval', (array) $this->option('exclude'));

        foreach (MarketTradingService::REGIONS as $id => $name) {
            if (in_array($id, $excluded, true)) {
                continue;
            }

            $this->info("Dispatching order sweep for {$name} ({$id})...");
            FetchRegionOrdersJob::dispatch($id);
        }

        $this->info('Region order sweeps dispatched.');

        return self::SUCCESS;
    }
}
