<?php

namespace App\Services\Esi\Diagnostics;

/** Default sink until the buffered ingestion path is enabled. */
class NullDiagnosticsSink implements EsiDiagnosticsSink
{
    public function push(array $event): void {}
}
