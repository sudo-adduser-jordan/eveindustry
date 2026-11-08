<?php

namespace App\Console\Commands;

use App\Jobs\PrecomputeManufacturingCostsChunk;
use App\Models\EveType;
use App\Services\ManufacturingBrowserService;
use App\Services\MarketTradingService;
use Illuminate\Console\Command;

class PrecomputeManufacturingCosts extends Command
{
    protected $signature = 'manufacturing:precompute-costs
        {--include-t2 : Include Tech II items}
        {--include-t3 : Include Tech III items}
        {--include-faction : Include Faction items}
        {--include-all : Include all meta group types}';

    protected $description = 'Precompute base manufacturing costs and profits for T1 items (by default) across all regions and ME levels 0-10';

    public function __construct(
        protected ManufacturingBrowserService $browser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $regions = array_keys(MarketTradingService::REGIONS);

        $allowedMetaGroups = $this->resolveAllowedMetaGroups();
        $includeAll = (bool) $this->option('include-all');

        $label = $includeAll ? 'all' : implode(', ', array_map(fn ($id) => match ($id) {
            EveType::META_GROUP_TECH_I => 'T1',
            EveType::META_GROUP_TECH_II => 'T2',
            EveType::META_GROUP_TECH_III => 'T3',
            EveType::META_GROUP_FACTION => 'Faction',
            default => "meta:{$id}",
        }, $allowedMetaGroups));

        $this->info("Dispatching manufacturing cost precomputation ({$label})...");

        $jobCount = 0;

        // Each job fetches and filters its own blueprint list, so we only pass
        // small scalar parameters instead of serializing the full item list
        // into every region's queue payload.
        foreach ($regions as $regionId) {
            $regionName = MarketTradingService::REGIONS[$regionId] ?? "Region {$regionId}";

            PrecomputeManufacturingCostsChunk::dispatch(
                $regionId,
                $allowedMetaGroups,
                $includeAll
            );
            $jobCount++;

            $this->info("Dispatched job for {$regionName} ({$regionId}), ME levels 0-10.");
        }

        $regionCount = count($regions);
        $this->info("Dispatched {$jobCount} precomputation jobs ({$regionCount} regions, each processing ME levels 0-10).");

        return self::SUCCESS;
    }

    /**
     * Resolve which meta group IDs are allowed based on command options.
     *
     * @return array<int>
     */
    private function resolveAllowedMetaGroups(): array
    {
        if ($this->option('include-all')) {
            return [];
        }

        $allowed = [EveType::META_GROUP_TECH_I];

        if ($this->option('include-t2')) {
            $allowed[] = EveType::META_GROUP_TECH_II;
        }
        if ($this->option('include-t3')) {
            $allowed[] = EveType::META_GROUP_TECH_III;
        }
        if ($this->option('include-faction')) {
            $allowed[] = EveType::META_GROUP_FACTION;
        }

        return $allowed;
    }
}
