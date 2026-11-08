<?php

namespace App\Http\Resources\V1\Corp;

use App\Models\StructureVulnerabilityWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A structure's weekly vulnerability window.
 *
 * @mixin StructureVulnerabilityWindow
 */
class StructureWindowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'solar_system_id' => (int) $this->solar_system_id,
            'solar_system_name' => (string) $this->solar_system_name,
            /** `upwell` or `skyhook`. */
            'structure_type' => (string) $this->structure_type,
            'structure_name' => (string) $this->structure_name,
            /** @var int|null 0 (Sunday) to 6; null for skyhooks, which are vulnerable daily. */
            'day_of_week' => $this->day_of_week,
            /** HH:MM, UTC. */
            'start_time_utc' => substr((string) $this->start_time_utc, 0, 5),
            'duration_minutes' => (int) $this->duration_minutes,
            /** @var string|null ISO 8601. */
            'reinforce_exit_at' => $this->reinforce_exit_at?->toIso8601String(),
            /** @var string|null */
            'notes' => $this->notes,
        ];
    }
}
