<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Optional re-pricing and paging for one scanned system.
 */
class MoonScanSystemRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Re-price the stored moons in this mode instead of the stored one. */
            'pricing_mode' => 'nullable|string|in:raw,compressed,refined',
        ], $this->paginationRules());
    }
}
