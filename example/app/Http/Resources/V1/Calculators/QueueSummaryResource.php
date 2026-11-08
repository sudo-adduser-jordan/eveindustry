<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;

/**
 * Build queue profit per job and in total (IndustryProfitCalculator::totals).
 */
class QueueSummaryResource extends CalculatorResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * Per job, in request order.
             *
             * @var list<array{revenue: float, input_cost: float, fees: float, profit: float, profit_margin: float}>
             */
            'items' => $this->resource['items'],
            /**
             * Sums, with the margin on total input cost.
             *
             * @var array{revenue: float, input_cost: float, fees: float, profit: float, profit_margin: float}
             */
            'totals' => $this->resource['totals'],
        ];
    }
}
