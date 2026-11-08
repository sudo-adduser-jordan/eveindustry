<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Create a reaction build program from reaction plan entries.
 */
class StoreReactionPlanProgramRequest extends ApiRequest
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
            'entries.*.formula_name' => 'nullable|string|max:255',
            'entries.*.slots' => 'required|integer|min:1|max:1000',
            'entries.*.runs_per_slot' => 'required|integer|min:1|max:100000',
            'settings' => 'nullable|array',
            'settings.reaction_structure_type' => 'nullable|string|in:athanor,tatara',
            'settings.reaction_rig_me' => 'nullable|string|in:none,t1,t2',
            'settings.reaction_system_security' => 'nullable|string|in:highsec,lowsec,nullsec',
        ];
    }
}
