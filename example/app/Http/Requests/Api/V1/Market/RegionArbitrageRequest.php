<?php

namespace App\Http\Requests\Api\V1\Market;

use App\Services\MarketTradingService;
use Illuminate\Validation\Validator;

class RegionArbitrageRequest extends MarketRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Region to buy in (source). Default 10000002 (The Forge / Jita). */
            'buy_region_id' => $this->regionRules(),
            /** Region to sell in (destination). Default 10000043 (Domain / Amarr). Must differ from buy_region_id. */
            'sell_region_id' => $this->regionRules(),
            'transport_isk_m3' => 'nullable|numeric|min:0',
        ], $this->scanRules(), $this->paginationRules());
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                // Raw input here: validated() would re-run the validator.
                $buy = (int) ($this->input('buy_region_id') ?? MarketTradingService::REGION_THE_FORGE);
                $sell = (int) ($this->input('sell_region_id') ?? MarketTradingService::REGION_DOMAIN);
                if ($buy === $sell) {
                    $validator->errors()->add('sell_region_id', 'Buy and sell regions must be different.');
                }
            },
        ];
    }

    public function buyRegionId(): int
    {
        return $this->regionInput('buy_region_id', MarketTradingService::REGION_THE_FORGE);
    }

    public function sellRegionId(): int
    {
        return $this->regionInput('sell_region_id', MarketTradingService::REGION_DOMAIN);
    }

    public function transportIskPerM3(): float
    {
        return (float) ($this->validated('transport_isk_m3') ?? MarketTradingService::DEFAULT_TRANSPORT_ISK_M3);
    }
}
