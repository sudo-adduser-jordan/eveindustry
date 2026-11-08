<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Filter for the user's own structures.
 */
class StructureIndexRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Only structures set up for this activity. */
            'activity_type' => 'nullable|string|in:manufacturing,invention,reaction',
        ], $this->paginationRules());
    }
}
