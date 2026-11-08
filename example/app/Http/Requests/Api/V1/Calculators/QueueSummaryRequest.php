<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Build queue jobs to total. Same rules as the old
 * POST /api/calculators/manufacturing/queue-summary.
 */
class QueueSummaryRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:500',
            'items.*.revenue' => 'required|numeric|min:0',
            'items.*.input_cost' => 'required|numeric|min:0',
            'items.*.fees' => 'required|numeric|min:0',
        ];
    }
}
