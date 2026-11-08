<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Change a structure. Only the fields sent are changed.
 */
class UpdateStructureRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'activity_type' => 'sometimes|required|string|in:manufacturing,invention,reaction',
            'name' => 'sometimes|required|string|max:100',
            'structure_type' => 'sometimes|required|string|in:none,raitaru,azbel,sotiyo,athanor,tatara',
            'rig_me' => 'sometimes|required|string|in:none,t1,t2',
            'rig_te' => 'sometimes|required|string|in:none,t1,t2',
            'system_security' => 'sometimes|required|string|in:highsec,lowsec,nullsec',
            'solar_system_id' => 'nullable|integer',
            /** Percent, 0 to 50. */
            'tax_rate' => 'nullable|numeric|min:0|max:50',
            /** Making it the default un-defaults the other structures of that activity type. */
            'is_default' => 'boolean',
        ];
    }
}
