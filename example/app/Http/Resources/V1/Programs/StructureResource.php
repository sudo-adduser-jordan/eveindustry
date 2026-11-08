<?php

namespace App\Http\Resources\V1\Programs;

use App\Models\StationConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A structure (station config) the user builds in.
 *
 * @mixin StationConfig
 */
class StructureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            /** `manufacturing`, `invention` or `reaction`. */
            'activity_type' => $this->activity_type,
            'name' => $this->name,
            'structure_type' => $this->structure_type,
            'rig_me' => $this->rig_me,
            'rig_te' => $this->rig_te,
            'system_security' => $this->system_security,
            /** @var int|null */
            'solar_system_id' => $this->solar_system_id,
            /** Percent. */
            'tax_rate' => $this->tax_rate === null ? null : (float) $this->tax_rate,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
