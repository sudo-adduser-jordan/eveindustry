<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ClearJobBatches extends Command
{
    protected $signature = 'queue:clear-batches
                            {--force : Skip confirmation prompt}';

    protected $description = 'Clear all job batches from the database';

    public function handle(): int
    {
        $count = DB::table('job_batches')->count();

        if ($count === 0) {
            $this->info('No job batches found.');

            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->confirm("Are you sure you want to delete all {$count} job batch(es)?")) {
            $this->info('Operation cancelled.');

            return self::SUCCESS;
        }

        DB::table('job_batches')->truncate();

        $this->info("Cleared {$count} job batch(es) from the database.");

        return self::SUCCESS;
    }
}
