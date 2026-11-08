<?php

namespace App\Console\Commands;

use App\Jobs\ImportPublicContracts as ImportPublicContractsJob;
use Illuminate\Console\Command;

class ImportPublicContracts extends Command
{
    protected $signature = 'contracts:import';

    protected $description = 'Download and import public contract data from everef.net';

    public function handle(): int
    {
        $this->info('Importing public contracts...');
        ImportPublicContractsJob::dispatchSync();
        $this->info('Public contracts import complete.');

        return self::SUCCESS;
    }
}
