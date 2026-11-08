<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Type search for the industry stock manager.
 */
class StockSearchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Case-insensitive substring of a published type name. */
            'q' => 'required|string|min:1|max:100',
        ];
    }

    public function term(): string
    {
        return (string) $this->validated('q');
    }
}
