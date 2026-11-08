<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Change the desired quantity of a stock item.
 */
class UpdateStockItemRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'desired_quantity' => ['required', 'integer', 'min:0', 'max:1000000000000'],
        ];
    }
}
