<?php

namespace App\Console\Commands;

use App\Jobs\RefreshIndustryData;
use App\Services\RefreshEligibility;
use Illuminate\Console\Command;

class RefreshIndustryCache extends Command
{
    protected $signature = 'industry:refresh';

    protected $description = 'Refresh industry data for recently active users with eligible ESI characters';

    public function handle(): int
    {
        $query = RefreshEligibility::users('industry');
        $total = (clone $query)->count();
        $this->info("Dispatching industry refresh for {$total} eligible users...");
        $index = 0;

        // Separate dataset phases and spread each cohort across its phase.
        $query->chunkById(500, function ($users) use (&$index, $total) {
            foreach ($users as $user) {
                $delay = 200 + (int) (($index++ / max(1, $total)) * 200);
                RefreshIndustryData::dispatch($user->id)->delay(now()->addSeconds($delay));
            }
        });

        return self::SUCCESS;
    }
}
