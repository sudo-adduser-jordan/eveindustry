<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Create a structure (station config).
 */
class StoreStructureRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'activity_type' => 'required|string|in:manufacturing,invention,reaction',
            'name' => 'required|string|max:100',
            'structure_type' => 'required|string|in:none,raitaru,azbel,sotiyo,athanor,tatara',
            'rig_me' => 'required|string|in:none,t1,t2',
            'rig_te' => 'required|string|in:none,t1,t2',
            'system_security' => 'required|string|in:highsec,lowsec,nullsec',
            'solar_system_id' => 'nullable|integer',
            /** Percent, 0 to 50. */
            'tax_rate' => 'nullable|numeric|min:0|max:50',
            /** Makes this the default for its activity type. */
            'is_default' => 'boolean',
        ];
    }
}
