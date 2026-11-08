<?php

namespace App\Http\Requests\Api\V1\Market;

class HistoryRequest extends MarketRequest
{
    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 365;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Trade hub region. Default 10000002 (The Forge / Jita). */
            'region_id' => $this->regionRules(),
            /** Number of most recent daily rows, 1 to 365. Default 30. */
            'days' => 'nullable|integer|min:1|max:'.self::MAX_DAYS,
        ], $this->paginationRules());
    }

    public function regionId(): int
    {
        return $this->regionInput('region_id');
    }

    public function days(): int
    {
        return (int) ($this->validated('days') ?? self::DEFAULT_DAYS);
    }
}
