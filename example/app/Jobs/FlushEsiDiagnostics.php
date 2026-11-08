<?php

namespace App\Jobs;

use App\Services\Esi\Diagnostics\EsiDiagnosticsFlusher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Short bounded drain of the ESI diagnostics buffer; continues itself only while a backlog remains. */
class FlushEsiDiagnostics implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(EsiDiagnosticsFlusher $flusher): void
    {
        $result = $flusher->run();
        if (! $result['locked'] && $result['events'] > 0 && $result['backlog'] > 0) {
            static::dispatch();
        }
    }
}
