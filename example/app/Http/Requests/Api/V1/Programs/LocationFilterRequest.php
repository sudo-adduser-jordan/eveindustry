<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Location filter of a personal build program.
 */
class LocationFilterRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Station/structure ids whose assets count as stock; empty or missing clears the filter. */
            'location_ids' => 'nullable|array',
            'location_ids.*' => 'integer',
        ];
    }

    /**
     * @return array<int, int>
     */
    public function locationIds(): array
    {
        return $this->validated('location_ids') ?? [];
    }
}
