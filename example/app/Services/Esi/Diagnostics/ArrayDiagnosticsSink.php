<?php

namespace App\Services\Esi\Diagnostics;

/** In-memory sink for tests. */
class ArrayDiagnosticsSink implements EsiDiagnosticsSink
{
    /** @var list<array> */
    public array $events = [];

    public function push(array $event): void
    {
        $this->events[] = $event;
    }
}
