<?php

namespace App\Http\Requests\Api\V1\Market;

class OrdersRequest extends MarketRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Type to list orders for. */
            'type_id' => 'required|integer|min:1',
            /** Trade hub region. Default 10000002 (The Forge / Jita). */
            'region_id' => $this->regionRules(),
            /** sell (cheapest first) or buy (highest first). Default sell. */
            'side' => 'nullable|string|in:sell,buy',
        ], $this->paginationRules());
    }

    public function typeId(): int
    {
        return (int) $this->validated('type_id');
    }

    public function regionId(): int
    {
        return $this->regionInput('region_id');
    }

    public function side(): string
    {
        return (string) ($this->validated('side') ?? 'sell');
    }
}
