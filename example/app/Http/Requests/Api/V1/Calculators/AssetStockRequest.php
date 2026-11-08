<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Type ids to look up in the user's assets. Same rules as the old
 * manufacturing and reaction planner stock routes.
 */
class AssetStockRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'type_ids' => 'required|array|min:1',
            'type_ids.*' => 'integer',
        ];
    }
}
