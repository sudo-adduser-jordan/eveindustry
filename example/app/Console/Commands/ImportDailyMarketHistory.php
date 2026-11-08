<?php

namespace App\Console\Commands;

use App\Jobs\ImportDailyMarketHistory as ImportDailyMarketHistoryJob;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportDailyMarketHistory extends Command
{
    protected $signature = 'market:import-daily-history
        {--date= : Import for a specific date (YYYY-MM-DD) instead of today and yesterday}';

    protected $description = 'Download and upsert daily market history from everef.net for today and the previous day';

    public function handle(): int
    {
        if ($dateOption = $this->option('date')) {
            $date = Carbon::createFromFormat('Y-m-d', $dateOption)->startOfDay();
            $this->info("Dispatching daily market history import for {$date->toDateString()}...");
            ImportDailyMarketHistoryJob::dispatch($date);
            $this->info('Job dispatched.');

            return self::SUCCESS;
        }

        $dates = [
            Carbon::today(),
            Carbon::yesterday(),
        ];

        foreach ($dates as $date) {
            $this->info("Dispatching daily market history import for {$date->toDateString()}...");
            ImportDailyMarketHistoryJob::dispatch($date);
        }

        $this->info('Daily market history import jobs dispatched.');

        return self::SUCCESS;
    }
}
