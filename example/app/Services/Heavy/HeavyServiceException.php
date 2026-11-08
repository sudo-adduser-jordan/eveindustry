<?php

namespace App\Services\Heavy;

use RuntimeException;

/**
 * A failure the old routes report as {"error": message} (or similar) with a
 * given status. Legacy controllers render it as they always did; the v1
 * controllers turn it into the matching /api/v1 error.
 */
class HeavyServiceException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
