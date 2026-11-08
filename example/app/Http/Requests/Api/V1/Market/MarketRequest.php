<?php

namespace App\Http\Requests\Api\V1\Market;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Services\MarketTradingService;
use Illuminate\Validation\Rule;

/**
 * Shared rules for /api/v1/market requests. Region ids must be one of the
 * five trade hubs in MarketTradingService::REGIONS.
 */
abstract class MarketRequest extends ApiRequest
{
    /**
     * @return list<mixed>
     */
    protected function regionRules(): array
    {
        return ['nullable', 'integer', Rule::in(array_keys(MarketTradingService::REGIONS))];
    }

    /**
     * Rules for the fee/margin/volume inputs shared by the trading scanners.
     *
     * @return array<string, string>
     */
    protected function scanRules(): array
    {
        return [
            'broker_fee' => 'nullable|numeric|min:0|max:10',
            'sales_tax' => 'nullable|numeric|min:0|max:15',
            'min_margin' => 'nullable|numeric|min:0|max:100',
            'min_daily_volume' => 'nullable|integer|min:0',
        ];
    }

    protected function regionInput(string $key, int $default = MarketTradingService::REGION_THE_FORGE): int
    {
        return (int) ($this->validated($key) ?? $default);
    }

    public function brokerFee(): float
    {
        return (float) ($this->validated('broker_fee') ?? MarketTradingService::DEFAULT_BROKER_FEE);
    }

    public function salesTax(): float
    {
        return (float) ($this->validated('sales_tax') ?? MarketTradingService::DEFAULT_SALES_TAX);
    }

    public function minMargin(): float
    {
        return (float) ($this->validated('min_margin') ?? MarketTradingService::DEFAULT_MIN_MARGIN);
    }

    public function minDailyVolume(): int
    {
        return (int) ($this->validated('min_daily_volume') ?? MarketTradingService::DEFAULT_MIN_DAILY_VOLUME);
    }
}
