<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneFailedJobs extends Command
{
    protected $signature = 'queue:prune-failed';

    protected $description = 'Delete old SQL failed-job records when retention is explicitly enabled';

    public function handle(): int
    {
        if (! config('failed_jobs.retention.enabled', false)) {
            $this->info('Failed-job retention is disabled.');

            return self::SUCCESS;
        }

        $days = max(1, (int) config('failed_jobs.retention.days', 90));
        $batchSize = max(1, min(5000, (int) config('failed_jobs.retention.batch_size', 500)));
        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = DB::table(config('queue.failed.table', 'failed_jobs'))
                ->where('failed_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($batchSize)
                ->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += DB::table(config('queue.failed.table', 'failed_jobs'))
                ->whereIn('id', $ids)
                ->delete();
        } while ($ids->count() === $batchSize);

        $this->info("Pruned {$deleted} failed-job record(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
