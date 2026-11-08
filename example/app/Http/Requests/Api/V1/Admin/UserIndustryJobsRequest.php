<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ApiRequest;

class UserIndustryJobsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Trade hub whose sell prices value the output. Unknown regions fall back to The Forge. */
            'region_id' => 'nullable|integer',
        ];
    }
}
