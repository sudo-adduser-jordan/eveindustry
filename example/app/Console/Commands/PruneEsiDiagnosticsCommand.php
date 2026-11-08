<?php

namespace App\Console\Commands;

use App\Services\Esi\Diagnostics\EsiDiagnosticsRetention;
use Illuminate\Console\Command;

class PruneEsiDiagnosticsCommand extends Command
{
    protected $signature = 'esi:diagnostics-prune';

    protected $description = 'Delete expired ESI diagnostics rows in bounded batches';

    public function handle(EsiDiagnosticsRetention $retention): int
    {
        foreach ($retention->prune() as $series => $rows) {
            $this->line("{$series}: {$rows}");
        }

        return self::SUCCESS;
    }
}
