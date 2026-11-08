<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

class InventionPlanStoreRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            't2_type_id' => 'required|integer',
            't2_type_name' => 'required|string|max:255',
            ...InventionPlanUpdateRequest::settingRules(),
        ];
    }
}
