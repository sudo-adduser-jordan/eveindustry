<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\MarketService;

/**
 * Live manufacturing browser profitability. Same settings as the old
 * POST /api/calculators/manufacturing-browser/calculate. One of
 * market_group_id or category_id is required: a 422 on market_group_id
 * here, where the old route returned {success: false, error}. Paging uses
 * the v1 defaults (50 per page, max 200) instead of the old 100/100.
 */
class ManufacturingBrowserCalculateRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return array_merge([
            'runs' => 'required|integer|min:1|max:100000',
            'me_level' => 'nullable|integer|min:0|max:10',
            'rig_tier' => 'nullable|string|in:none,t1,t2',
            'security_space' => 'nullable|string|in:highsec,lowsec,nullsec',
            'facility_tax' => 'nullable|numeric|min:0|max:50',
            'system_cost_index' => 'nullable|numeric|min:0|max:100',
            'price_region_id' => 'nullable|integer',
            'market_group_id' => 'nullable|integer|required_without:category_id',
            'category_id' => 'nullable|integer',
        ], $this->paginationRules());
    }

    public function regionId(): int
    {
        return (int) ($this->validated('price_region_id') ?? MarketService::DEFAULT_REGION_ID);
    }

    /**
     * Validated input with the v1 paging filled in, for the shared service.
     *
     * @return array<string, mixed>
     */
    public function calculatorInput(): array
    {
        return array_merge($this->validated(), ['page' => $this->page(), 'per_page' => $this->perPage()]);
    }
}
