<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Partial update: only the fields sent are changed. When `entries` is sent it
 * replaces the whole list.
 */
class ReactionPlanUpdateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'entries' => 'sometimes|array|min:1',
            'entries.*.formula_type_id' => 'required_with:entries|integer',
            'entries.*.formula_name' => 'required_with:entries|string',
            'entries.*.icon_url' => 'nullable|string',
            'entries.*.slots' => 'required_with:entries|integer|min:1|max:1000',
            'entries.*.runs_per_slot' => 'required_with:entries|integer|min:1|max:100000',
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
            'default_runs_per_slot' => 'nullable|integer|min:1|max:100000',
            'rig_bonus' => 'nullable|numeric|min:0|max:10',
            'structure_type' => 'nullable|string|in:athanor,tatara',
            'rig_tier' => 'nullable|string|in:none,t1,t2',
            'security_space' => 'nullable|string|in:lowsec,nullsec',
            'facility_tax' => 'nullable|numeric|min:0|max:50',
            'station_id' => 'nullable|integer',
            'solar_system_id' => 'nullable|integer',
            'price_region_id' => 'nullable|integer',
        ];
    }
}
