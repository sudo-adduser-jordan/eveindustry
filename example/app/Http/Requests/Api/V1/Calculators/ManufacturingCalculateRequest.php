<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\MarketService;

/**
 * One manufacturing job. Same rules as the old
 * POST /api/calculators/manufacturing/calculate.
 */
class ManufacturingCalculateRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'blueprint_type_id' => 'required|integer',
            'product_type_id' => 'required|integer',
            'runs' => 'required|integer|min:1|max:100000',
            'me_level' => 'required|integer|min:0|max:10',
            'te_level' => 'required|integer|min:0|max:20',
            'industry_skill' => 'required|integer|min:0|max:5',
            'advanced_industry_skill' => 'required|integer|min:0|max:5',
            'structure_me_bonus' => 'nullable|numeric|min:0|max:10',
            // Max 55 accommodates a nullsec T2 TE rig: 3% structure + 24% x 2.1 = 53.4%
            'structure_te_bonus' => 'nullable|numeric|min:0|max:55',
            'reaction_me_bonus' => 'nullable|numeric|min:0|max:10',
            'materials' => 'nullable|array',
            'materials.*.type_id' => 'required|integer',
            'materials.*.name' => 'required|string',
            'materials.*.quantity' => 'required|integer|min:0',
            'base_time' => 'nullable|integer|min:0',
            'portion_size' => 'nullable|integer|min:1',
            'region_id' => 'nullable|integer',
            'buyback_percentage' => 'nullable|numeric|min:0|max:100',
            'solar_system_id' => 'nullable|integer',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'reaction_solar_system_id' => 'nullable|integer',
            'reaction_tax_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function regionId(): int
    {
        return (int) ($this->validated('region_id') ?? MarketService::DEFAULT_REGION_ID);
    }
}
