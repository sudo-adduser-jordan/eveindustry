<?php

namespace App\Http\Requests\Api\V1\Market;

use App\Http\Requests\Api\V1\ApiRequest;

class AppraisalRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Item names, one per line; repeated lines add to the quantity. */
            'items' => 'required|string|max:50000',
            /** Which contracts to price from: all, sell (items offered) or buy (items wanted). Default all. */
            'filter' => 'nullable|string|in:all,sell,buy',
            /** Per-unit price: average (mean) or lowest. Default average. */
            'method' => 'nullable|string|in:average,lowest',
        ];
    }

    public function itemsText(): string
    {
        return (string) $this->validated('items');
    }

    public function contractFilter(): string
    {
        return (string) ($this->validated('filter') ?? 'all');
    }

    public function priceMethod(): string
    {
        return (string) ($this->validated('method') ?? 'average');
    }
}
