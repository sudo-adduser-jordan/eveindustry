<?php

namespace App\Console\Commands;

use App\Jobs\RefreshAssetData;
use App\Services\RefreshEligibility;
use Illuminate\Console\Command;

class RefreshAssetCache extends Command
{
    protected $signature = 'assets:refresh';

    protected $description = 'Refresh asset data for recently active users with eligible ESI characters';

    public function handle(): int
    {
        $query = RefreshEligibility::users('assets');
        $total = (clone $query)->count();
        $this->info("Dispatching asset refresh for {$total} eligible users...");
        $index = 0;

        // Separate dataset phases and spread each cohort across its phase.
        $query->chunkById(500, function ($users) use (&$index, $total) {
            foreach ($users as $user) {
                $delay = 0 + (int) (($index++ / max(1, $total)) * 200);
                RefreshAssetData::dispatch($user->id)->delay(now()->addSeconds($delay));
            }
        });

        return self::SUCCESS;
    }
}
