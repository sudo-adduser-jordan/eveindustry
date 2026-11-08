<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\MarketService;

/**
 * A reaction plan. Same rules as the old
 * POST /api/calculators/reaction-planner/calculate.
 */
class ReactionPlannerCalculateRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'entries' => 'required|array|min:1',
            'entries.*.formula_type_id' => 'required|integer',
            'entries.*.formula_name' => 'nullable|string',
            'entries.*.slots' => 'required|integer|min:1|max:1000',
            'entries.*.runs_per_slot' => 'required|integer|min:1|max:100000',
            'structure_type' => 'nullable|string|in:athanor,tatara',
            'rig_tier' => 'nullable|string|in:none,t1,t2',
            'security_space' => 'nullable|string|in:lowsec,nullsec',
            'facility_tax' => 'nullable|numeric|min:0|max:50',
            'system_cost_index' => 'nullable|numeric|min:0|max:100',
            'price_region_id' => 'nullable|integer',
        ];
    }

    public function regionId(): int
    {
        return (int) ($this->validated('price_region_id') ?? MarketService::DEFAULT_REGION_ID);
    }
}
