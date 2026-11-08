<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\MarketService;

/**
 * Reaction browser settings (same as the old
 * POST /api/calculators/reaction-browser/calculate) plus page/per_page:
 * the old route returned every reaction in one list.
 */
class ReactionBrowserRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return array_merge([
            'runs' => 'required|integer|min:1|max:100000',
            'rig_tier' => 'nullable|string|in:none,t1,t2',
            'security_space' => 'nullable|string|in:lowsec,nullsec',
            'facility_tax' => 'nullable|numeric|min:0|max:50',
            'system_cost_index' => 'nullable|numeric|min:0|max:100',
            'price_region_id' => 'nullable|integer',
        ], $this->paginationRules());
    }

    public function regionId(): int
    {
        return (int) ($this->validated('price_region_id') ?? MarketService::DEFAULT_REGION_ID);
    }
}
