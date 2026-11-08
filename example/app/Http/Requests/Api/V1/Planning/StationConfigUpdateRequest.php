<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Partial update: only the fields sent are changed.
 */
class StationConfigUpdateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:100',
            'structure_type' => 'sometimes|required|string|in:none,raitaru,azbel,sotiyo,athanor,tatara',
            'rig_me' => 'sometimes|required|string|in:none,t1,t2',
            'rig_te' => 'sometimes|required|string|in:none,t1,t2',
            'system_security' => 'sometimes|required|string|in:highsec,lowsec,nullsec',
            'is_default' => 'boolean',
        ];
    }
}
