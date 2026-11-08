<?php

namespace App\Services\Calculators;

use RuntimeException;

/**
 * A calculator could not run with the given input (e.g. a blueprint with no
 * materials). The old routes turn it into their {"error": ...} 422 body; the
 * v1 routes into a validation error on $field.
 */
class CalculationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
