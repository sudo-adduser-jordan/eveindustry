<?php

namespace App\Console\Commands;

use App\Jobs\FlushEsiDiagnostics;
use Illuminate\Console\Command;

class FlushEsiDiagnosticsCommand extends Command
{
    protected $signature = 'esi:diagnostics-flush {--now : Drain inline instead of queueing}';

    protected $description = 'Drain the ESI diagnostics buffer into MySQL';

    public function handle(): int
    {
        if (! config('esi_diagnostics.enabled') && config('esi_diagnostics.sink') !== 'redis') {
            return self::SUCCESS;
        }
        if ($this->option('now')) {
            FlushEsiDiagnostics::dispatchSync();
        } else {
            FlushEsiDiagnostics::dispatch();
        }

        return self::SUCCESS;
    }
}
