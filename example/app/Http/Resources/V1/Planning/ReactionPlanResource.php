<?php

namespace App\Http\Resources\V1\Planning;

use App\Models\ReactionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved reaction planner setup.
 *
 * @mixin ReactionPlan
 */
class ReactionPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => $this->id,
            'name' => $this->name,
            /** @var list<array{formula_type_id: int, formula_name: string, icon_url?: string|null, slots: int, runs_per_slot: int}> */
            'entries' => $this->entries ?? [],
            /** @var int */
            'default_runs_per_slot' => $this->default_runs_per_slot,
            'rig_bonus' => (float) $this->rig_bonus,
            /** @var string athanor or tatara. */
            'structure_type' => $this->structure_type,
            /** @var string none, t1 or t2. */
            'rig_tier' => $this->rig_tier,
            /** @var string lowsec or nullsec. */
            'security_space' => $this->security_space,
            /** Facility tax in percent. */
            'facility_tax' => (float) $this->facility_tax,
            /** @var int|null */
            'station_id' => $this->station_id,
            /** @var int|null */
            'solar_system_id' => $this->solar_system_id,
            /** @var int|null */
            'price_region_id' => $this->price_region_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
