<?php

namespace App\Http\Resources\V1\Planning;

use App\Models\InventionPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved invention planner setup.
 *
 * @mixin InventionPlan
 */
class InventionPlanResource extends JsonResource
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
            /** @var int */
            't2_type_id' => $this->t2_type_id,
            /** @var string */
            't2_type_name' => $this->t2_type_name,
            /** @var string|null */
            'decryptor_key' => $this->decryptor_key,
            /** @var int */
            't1_me' => $this->t1_me,
            /** @var int */
            't1_te' => $this->t1_te,
            /** @var int */
            'encryption_skill' => $this->encryption_skill,
            /** @var int */
            'science1_skill' => $this->science1_skill,
            /** @var int */
            'science2_skill' => $this->science2_skill,
            /** @var int */
            'invention_runs' => $this->invention_runs,
            /** @var string none, raitaru, azbel or sotiyo. */
            'structure_type' => $this->structure_type,
            /** @var string none, t1 or t2. */
            'rig_me' => $this->rig_me,
            /** @var string none, t1 or t2. */
            'rig_te' => $this->rig_te,
            /** @var string highsec, lowsec or nullsec. */
            'system_security' => $this->system_security,
            /** Facility tax in percent. */
            'facility_tax' => (float) $this->facility_tax,
            /** @var int|null */
            'solar_system_id' => $this->solar_system_id,
            /** @var int|null */
            'station_id' => $this->station_id,
            /** @var int|null */
            'price_region_id' => $this->price_region_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
