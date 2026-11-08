<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

class ReactionPlanStoreRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'entries' => 'required|array|min:1',
            'entries.*.formula_type_id' => 'required|integer',
            'entries.*.formula_name' => 'required|string',
            'entries.*.icon_url' => 'nullable|string',
            'entries.*.slots' => 'required|integer|min:1|max:1000',
            'entries.*.runs_per_slot' => 'required|integer|min:1|max:100000',
            ...ReactionPlanUpdateRequest::settingRules(),
        ];
    }
}
