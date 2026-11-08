<?php

namespace App\Services\Esi\Diagnostics;

/** Destination for compact, already-bounded diagnostic events. Must not throw for normal failures. */
interface EsiDiagnosticsSink
{
    public function push(array $event): void;
}
