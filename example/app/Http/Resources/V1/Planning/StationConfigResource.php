<?php

namespace App\Http\Resources\V1\Planning;

use App\Models\StationConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved manufacturing station configuration.
 *
 * @mixin StationConfig
 */
class StationConfigResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            /** @var string none, raitaru, azbel, sotiyo, athanor or tatara. */
            'structure_type' => $this->structure_type,
            /** @var string none, t1 or t2. */
            'rig_me' => $this->rig_me,
            /** @var string none, t1 or t2. */
            'rig_te' => $this->rig_te,
            /** @var string highsec, lowsec or nullsec. */
            'system_security' => $this->system_security,
            'solar_system_id' => $this->solar_system_id,
            'tax_rate' => $this->tax_rate === null ? null : (float) $this->tax_rate,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
