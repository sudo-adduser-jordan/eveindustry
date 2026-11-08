<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\MarketService;

/**
 * One reaction job. Same rules as the old
 * POST /api/calculators/reactions/calculate.
 */
class ReactionsCalculateRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'formula_type_id' => 'required|integer',
            'product_type_id' => 'required|integer',
            'runs' => 'required|integer|min:1|max:100000',
            'reactions_skill' => 'required|integer|min:0|max:5',
            'rig_bonus' => 'nullable|numeric|min:0|max:10',
            'structure_te_bonus' => 'nullable|numeric|min:0|max:30',
            'materials' => 'nullable|array',
            'materials.*.type_id' => 'required|integer',
            'materials.*.name' => 'required|string',
            'materials.*.quantity' => 'required|integer|min:0',
            'base_time' => 'nullable|integer|min:0',
            'portion_size' => 'nullable|integer|min:1',
            'region_id' => 'nullable|integer',
        ];
    }

    public function regionId(): int
    {
        return (int) ($this->validated('region_id') ?? MarketService::DEFAULT_REGION_ID);
    }
}
