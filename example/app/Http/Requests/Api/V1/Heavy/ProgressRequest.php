<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Optional location filter for a personal build program's progress.
 */
class ProgressRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Only count stock held at these root station/structure ids. */
            'location_ids' => 'nullable|array|max:500',
            'location_ids.*' => 'integer',
        ];
    }

    /**
     * @return list<int>
     */
    public function locationIds(): array
    {
        return array_map('intval', $this->validated('location_ids') ?? []);
    }
}
