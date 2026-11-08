<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDashboardStats;
use App\Services\RefreshEligibility;
use Illuminate\Console\Command;

class RefreshDashboardData extends Command
{
    protected $signature = 'dashboard:refresh';

    protected $description = 'Refresh dashboard data for recently active users with eligible ESI characters';

    public function handle(): int
    {
        $query = RefreshEligibility::users('dashboard');
        $total = (clone $query)->count();
        $this->info("Dispatching dashboard refresh for {$total} eligible users...");
        $index = 0;

        // Separate dataset phases and spread each cohort across its phase.
        $query->chunkById(500, function ($users) use (&$index, $total) {
            foreach ($users as $user) {
                $delay = 400 + (int) (($index++ / max(1, $total)) * 200);
                RefreshDashboardStats::dispatch($user->id)->delay(now()->addSeconds($delay));
            }
        });

        return self::SUCCESS;
    }
}
