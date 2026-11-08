<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Partial update: only the fields sent are changed.
 */
class InventionPlanUpdateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            't2_type_id' => 'sometimes|integer',
            't2_type_name' => 'sometimes|string|max:255',
            ...self::settingRules(),
        ];
    }

    /**
     * Rules for the optional planner settings, shared with the store request.
     *
     * @return array<string, string>
     */
    public static function settingRules(): array
    {
        return [
            'decryptor_key' => 'nullable|string|max:50',
            't1_me' => 'nullable|integer|min:0|max:10',
            't1_te' => 'nullable|integer|min:0|max:20',
            'encryption_skill' => 'nullable|integer|min:0|max:5',
            'science1_skill' => 'nullable|integer|min:0|max:5',
            'science2_skill' => 'nullable|integer|min:0|max:5',
            'invention_runs' => 'nullable|integer|min:1|max:10000',
            'structure_type' => 'nullable|string|in:none,raitaru,azbel,sotiyo',
            'rig_me' => 'nullable|string|in:none,t1,t2',
            'rig_te' => 'nullable|string|in:none,t1,t2',
            'system_security' => 'nullable|string|in:highsec,lowsec,nullsec',
            'facility_tax' => 'nullable|numeric|min:0|max:50',
            'solar_system_id' => 'nullable|integer',
            'station_id' => 'nullable|integer',
            'price_region_id' => 'nullable|integer',
        ];
    }
}
