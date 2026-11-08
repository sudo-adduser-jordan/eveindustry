<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;

/**
 * Factory planet profitability for one tier after customs tax
 * (FactoryPlannerCalculator). Same fields as the old route.
 */
class FactoryPlanResource extends CalculatorResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'tier' => $r['tier'],
            'tier_label' => $r['tier_label'],
            'factory_slots' => $r['factory_slots'],
            'tax_rate' => $r['tax_rate'],
            /** One row per output product, daily figures per planet (FactoryPlanetPlannerService). */
            'results' => self::clean($r['results']),
        ];
    }
}
