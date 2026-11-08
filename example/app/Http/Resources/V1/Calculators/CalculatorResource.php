<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for the calculator results. The resource is the array a
 * App\Services\Calculators service returned for the old route.
 *
 * v1 leaves out the old display strings (every "*_formatted" key, e.g.
 * "1.50K"); the numbers they were made from are always present.
 */
abstract class CalculatorResource extends JsonResource
{
    /**
     * Service rows (materials, components, items) with the display strings
     * removed, at any depth.
     *
     * @param  array<mixed>  $rows
     * @return array<mixed>
     */
    protected static function clean(array $rows): array
    {
        $out = [];
        foreach ($rows as $key => $value) {
            if (is_string($key) && str_ends_with($key, '_formatted')) {
                continue;
            }
            $out[$key] = is_array($value) ? self::clean($value) : $value;
        }

        return $out;
    }
}
