<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Location filters of a corporate build program.
 */
class CorporationLocationFilterRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Locations whose assets count as material stock. */
            'input_location_ids' => 'nullable|array',
            'input_location_ids.*' => 'integer',
            /** Locations whose assets count as finished-product stock. */
            'output_location_ids' => 'nullable|array',
            'output_location_ids.*' => 'integer',
        ];
    }

    /**
     * @return array<int, int>
     */
    public function inputLocationIds(): array
    {
        return $this->validated('input_location_ids') ?? [];
    }

    /**
     * @return array<int, int>
     */
    public function outputLocationIds(): array
    {
        return $this->validated('output_location_ids') ?? [];
    }
}
