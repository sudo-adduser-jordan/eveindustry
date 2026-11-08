<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Merge several build programs into a new one.
 */
class MergeBuildProgramsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Ids of the programs to merge, in section order (at least two). */
            'program_ids' => 'required|array|min:2',
            'program_ids.*' => 'required|integer',
            'name' => 'required|string|max:255',
        ];
    }
}
