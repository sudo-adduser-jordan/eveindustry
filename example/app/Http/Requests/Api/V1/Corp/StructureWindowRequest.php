<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;
use App\Models\StructureVulnerabilityWindow;

/**
 * Add (POST) or change (PUT) a structure vulnerability window.
 */
class StructureWindowRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'solar_system_id' => 'required|integer',
            /** Defaults to the SDE name. */
            'solar_system_name' => 'nullable|string|max:100',
            /** `upwell` or `skyhook`. */
            'structure_type' => 'required|string|in:'.implode(',', StructureVulnerabilityWindow::TYPES),
            'structure_name' => 'required|string|max:150',
            /** 0 (Sunday) to 6. Ignored for skyhooks, which are vulnerable daily. */
            'day_of_week' => 'nullable|integer|min:0|max:6',
            /** HH:MM, UTC. */
            'start_time_utc' => 'required|date_format:H:i',
            'duration_minutes' => 'required|integer|min:5|max:1440',
            /** When the structure leaves reinforcement, if it is reinforced. */
            'reinforce_exit_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ];
    }
}
