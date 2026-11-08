<?php

namespace App\Console\Commands;

use App\Models\MarketPrice;
use App\Services\MarketService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UpdateMarketPrices extends Command
{
    protected $signature = 'market:update-prices
        {--region= : Region ID (default: The Forge/10000002)}
        {--seed-from-sde : Seed tracking for all marketable types from local SDE data before updating}';

    protected $description = 'Fetch and update market prices from EVE ESI for all tracked types';

    public function __construct(protected MarketService $marketService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $regionId = (int) ($this->option('region') ?: MarketService::DEFAULT_REGION_ID);

        $this->info('Updating global market prices from ESI...');
        $globalCount = $this->marketService->fetchAndStoreGlobalPrices();
        $this->info("Updated {$globalCount} global prices.");

        // Optionally seed market tracking for all marketable types from local SDE data.
        // This ensures that fetchAndStoreGlobalPrices creates entries for types
        // we already know about, and regional updates pick them up on the next run.
        if ($this->option('seed-from-sde')) {
            $marketableIds = $this->marketService->getMarketableTypeIds();
            if (!empty($marketableIds)) {
                $existingIds = MarketPrice::where('region_id', $regionId)
                    ->pluck('type_id')
                    ->all();
                $newIds = array_diff($marketableIds, $existingIds);

                if (!empty($newIds)) {
                    $now = now();
                    $batch = [];
                    foreach ($newIds as $typeId) {
                        $batch[] = [
                            'type_id'    => $typeId,
                            'region_id'  => $regionId,
                            'fetched_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    // Insert in chunks to avoid memory issues
                    foreach (array_chunk($batch, 1000) as $chunk) {
                        MarketPrice::insertOrIgnore($chunk);
                    }

                    $this->info("Seeded " . count($newIds) . " new marketable types from SDE data.");
                } else {
                    $this->info('All marketable types already tracked.');
                }
            } else {
                $this->warn('No marketable types found in SDE data. Run sde:import first.');
            }
        }

        // Update regional prices for all types we already track
        $trackedTypeIds = MarketPrice::where('region_id', $regionId)
            ->distinct()
            ->pluck('type_id')
            ->all();

        if (count($trackedTypeIds) > 0) {
            $this->info("Updating regional prices for " . count($trackedTypeIds) . " tracked types in region {$regionId}...");
            $regionalCount = $this->marketService->fetchAndStoreRegionalPrices($trackedTypeIds, $regionId);
            $this->info("Updated {$regionalCount} regional prices.");
        } else {
            $this->info('No tracked types found for regional price updates.');
        }

        Log::info('Market prices updated', [
            'global_count' => $globalCount,
            'regional_count' => count($trackedTypeIds),
            'region_id' => $regionId,
        ]);

        $this->info('Market price update complete.');

        return self::SUCCESS;
    }
}
