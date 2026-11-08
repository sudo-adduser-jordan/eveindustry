<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\MarketService;

/**
 * The invention + T2 manufacturing chain. Same rules as the old
 * POST /api/calculators/invention-planner/calculate.
 */
class InventionCalculateRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            't2_type_id' => 'required|integer',
            'decryptor_key' => 'nullable|string',
            'encryption_skill' => 'required|integer|min:0|max:5',
            'science1_skill' => 'required|integer|min:0|max:5',
            'science2_skill' => 'required|integer|min:0|max:5',
            'base_probability' => 'nullable|numeric|min:0|max:1',
            'base_runs' => 'nullable|integer|min:1|max:1000',
            'invention_runs' => 'required|integer|min:1|max:10000',
            't1_me' => 'nullable|integer|min:0|max:10',
            't1_te' => 'nullable|integer|min:0|max:20',
            'industry_skill' => 'nullable|integer|min:0|max:5',
            'advanced_industry_skill' => 'nullable|integer|min:0|max:5',
            'structure_type' => 'nullable|string|in:none,raitaru,azbel,sotiyo',
            'rig_me' => 'nullable|string|in:none,t1,t2',
            'rig_te' => 'nullable|string|in:none,t1,t2',
            'system_security' => 'nullable|string|in:highsec,lowsec,nullsec',
            'reaction_structure_type' => 'nullable|string|in:none,athanor,tatara',
            'reaction_rig_me' => 'nullable|string|in:none,t1,t2',
            'reaction_system_security' => 'nullable|string|in:highsec,lowsec,nullsec',
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
