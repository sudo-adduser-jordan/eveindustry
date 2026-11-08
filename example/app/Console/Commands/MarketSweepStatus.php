<?php

namespace App\Console\Commands;

use App\Services\MarketOrderSweepService;
use App\Services\MarketService;
use App\Services\MarketTradingService;
use Illuminate\Console\Command;

/**
 * Shows the latest order sweep per trade hub and how long it took, so the
 * Jita 15-minute cadence (allowed while a sweep takes under 10 minutes) can
 * be checked against real timings.
 */
class MarketSweepStatus extends Command
{
    protected $signature = 'market:sweep-status';

    protected $description = 'Show the latest region order sweep per trading hub, its duration and the Jita cadence';

    public function handle(MarketOrderSweepService $sweeps): int
    {
        $rows = [];

        foreach (MarketTradingService::REGIONS as $regionId => $name) {
            $latest = $sweeps->latest($regionId);
            $completed = $sweeps->lastCompleted($regionId);

            $rows[] = [
                "{$name} ({$regionId})",
                $latest?->status ?? '-',
                $latest?->started_at?->toDateTimeString() ?? '-',
                $latest ? "{$latest->pages_fetched}/{$latest->pages_total} ({$latest->pages_not_modified} unchanged)" : '-',
                $latest?->orders_seen ?? '-',
                $completed?->started_at?->toDateTimeString() ?? '-',
                $completed?->duration_seconds !== null ? $completed->duration_seconds.'s' : '-',
            ];
        }

        $this->table(
            ['Region', 'Latest', 'Started', 'Pages', 'Orders', 'Last complete', 'Took'],
            $rows,
        );

        $cadence = $sweeps->cadenceMinutes(MarketService::DEFAULT_REGION_ID);
        $this->line("Jita cadence: every {$cadence} minutes (15 while the last complete sweep took under "
            .MarketOrderSweepService::FAST_CADENCE_MAX_SECONDS.'s).');

        return self::SUCCESS;
    }
}
