<?php

namespace App\Services\Esi;

final class EsiRequestException extends \RuntimeException
{
    public function __construct(
        public readonly string $outcome,
        public readonly int $retryAfterSeconds = 0,
        public readonly ?EsiResponse $response = null,
    ) {
        // Never include upstream bodies, credentials or full SQL in queued failures.
        parent::__construct('ESI request failed: '.$outcome);
    }

    public function retryable(): bool
    {
        return in_array($this->outcome, ['rate_limited', 'error_limited', 'transient', 'coordination', 'incomplete'], true);
    }
}
