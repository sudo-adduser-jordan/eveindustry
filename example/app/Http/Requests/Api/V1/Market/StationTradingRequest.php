<?php

namespace App\Http\Requests\Api\V1\Market;

class StationTradingRequest extends MarketRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Trade hub region. Default 10000002 (The Forge / Jita). */
            'region_id' => $this->regionRules(),
        ], $this->scanRules(), $this->paginationRules());
    }

    public function regionId(): int
    {
        return $this->regionInput('region_id');
    }
}
