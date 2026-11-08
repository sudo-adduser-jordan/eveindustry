<?php

namespace App\Http\Requests\Api\V1\Market;

use App\Services\MarketTradingService;

class PricesRequest extends MarketRequest
{
    public const MAX_TYPE_IDS = 200;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Type ids to price (`type_ids[]=34&type_ids[]=35`), 1 to 200. Duplicates are ignored. */
            'type_ids' => 'required|array|min:1|max:'.self::MAX_TYPE_IDS,
            'type_ids.*' => 'required|integer|min:1',
            /** Trade hub region. Default 10000002 (The Forge / Jita). */
            'region_id' => $this->regionRules(),
        ];
    }

    /**
     * @return list<int>
     */
    public function typeIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated('type_ids'))));
    }

    public function regionId(): int
    {
        return $this->regionInput('region_id', MarketTradingService::REGION_THE_FORGE);
    }
}
