<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\FactoryPlanetPlannerService;

/**
 * Factory planet planner settings. tier defaults to p2_to_p3 and region_id
 * to Jita, as on the old route. An unknown tier is a 422 (the old route
 * returned 400) and tax_rate must be 0-100 (the old route clamped it).
 */
class FactoryPlannerRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'tier' => 'nullable|string|in:'.implode(',', array_keys(FactoryPlanetPlannerService::TIER_LABELS)),
            'region_id' => 'nullable|integer',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function tier(): string
    {
        return (string) ($this->validated('tier') ?? 'p2_to_p3');
    }

    public function regionId(): int
    {
        return (int) ($this->validated('region_id') ?? FactoryPlanetPlannerService::DEFAULT_REGION_ID);
    }

    public function taxRate(): float
    {
        return (float) ($this->validated('tax_rate') ?? 0);
    }
}
