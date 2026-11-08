<?php

namespace App\Http\Requests\Api\V1\Programs;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Create a build program (personal or corporate) from calculator steps.
 */
class StoreBuildProgramRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            /** The product the program builds, for display. */
            't2_type_id' => 'nullable|integer',
            't2_type_name' => 'nullable|string|max:255',
            /** Top-level steps; the `materials` of each step are resolved into child steps. */
            'steps' => 'required|array',
            'steps.*.type_id' => 'required|integer',
            'steps.*.name' => 'required|string|max:255',
            'steps.*.quantity_required' => 'required|integer|min:1',
            'steps.*.runs_needed' => 'nullable|integer|min:0',
            /** @var list<array<string, mixed>>|null */
            'steps.*.materials' => 'nullable|array',
            /**
             * Calculator settings stored with the program (free-form).
             *
             * @var array<string, mixed>|null
             */
            'settings' => 'nullable|array',
        ];
    }

    /**
     * The validated input with the optional t2 fields always present.
     *
     * @return array<string, mixed>
     */
    public function programData(): array
    {
        return $this->validated() + ['t2_type_id' => null, 't2_type_name' => null];
    }
}
