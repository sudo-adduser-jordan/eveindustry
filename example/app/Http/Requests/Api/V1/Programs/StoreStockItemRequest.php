<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Set the desired stock of a type (creates the item or updates the existing one).
 */
class StoreStockItemRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type_id' => ['required', 'integer', 'exists:eve_types,type_id'],
            'desired_quantity' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ];
    }
}
