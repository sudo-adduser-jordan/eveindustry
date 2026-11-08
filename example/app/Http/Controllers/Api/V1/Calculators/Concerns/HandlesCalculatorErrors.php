<?php

namespace App\Http\Controllers\Api\V1\Calculators\Concerns;

use App\Services\Calculators\CalculationException;
use Illuminate\Validation\ValidationException;

trait HandlesCalculatorErrors
{
    /**
     * Run a calculator, turning unusable input (e.g. a blueprint with no
     * materials) into a 422 validation error on the offending field.
     *
     * @template T
     *
     * @param  callable(): T  $calculate
     * @return T
     *
     * @throws ValidationException
     */
    protected function calculateOr422(callable $calculate): mixed
    {
        try {
            return $calculate();
        } catch (CalculationException $e) {
            throw ValidationException::withMessages([$e->field => $e->getMessage()]);
        }
    }
}
