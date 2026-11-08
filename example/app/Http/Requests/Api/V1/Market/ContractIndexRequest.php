<?php

namespace App\Http\Requests\Api\V1\Market;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Filters for the public contract browser.
 */
class ContractIndexRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Only contracts of this type. */
            'type' => 'nullable|string|in:item_exchange,auction,courier',
            /** Contracts whose title contains this text. */
            'search' => 'nullable|string|max:100',
        ], $this->paginationRules());
    }
}
