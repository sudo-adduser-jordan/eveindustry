<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Optional input/output location filters for a corporate build program's progress.
 */
class CorporateProgressRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Count materials only at these root station/structure ids. */
            'input_location_ids' => 'nullable|array|max:500',
            'input_location_ids.*' => 'integer',
            /** Count finished products only at these root station/structure ids. */
            'output_location_ids' => 'nullable|array|max:500',
            'output_location_ids.*' => 'integer',
        ];
    }

    /**
     * @return list<int>
     */
    public function inputLocationIds(): array
    {
        return array_map('intval', $this->validated('input_location_ids') ?? []);
    }

    /**
     * @return list<int>
     */
    public function outputLocationIds(): array
    {
        return array_map('intval', $this->validated('output_location_ids') ?? []);
    }
}
