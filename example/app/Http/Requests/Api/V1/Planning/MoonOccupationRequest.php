<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

class MoonOccupationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'occupied' => 'required|boolean',
            /** Ignored (and cleared) when `occupied` is false. */
            'corporation_id' => 'nullable|integer',
            'corporation_name' => 'nullable|string|max:100',
        ];
    }
}
