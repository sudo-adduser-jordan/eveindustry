<?php

namespace App\Http\Resources\V1\Heavy;

use App\Models\StationConfig;
use App\Services\Calculators\StructureBonuses;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the user's station/structure configs.
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
        /** @var StationConfig $s */
        $s = $this->resource;

        return [
            'id' => (int) $s->id,
            'activity_type' => $s->activity_type,
            'name' => $s->name,
            'structure_type' => $s->structure_type,
            'rig_me' => $s->rig_me,
            'rig_te' => $s->rig_te,
            'system_security' => $s->system_security,
            /** @var int|null */
            'solar_system_id' => $s->solar_system_id,
            /** @var string|null Set by the controller from the SDE; null without a system. */
            'solar_system_name' => $s->getAttribute('solar_system_name'),
            /** Percent. */
            'tax_rate' => $s->tax_rate !== null ? (float) $s->tax_rate : null,
            'is_default' => (bool) $s->is_default,
            /**
             * The material and time bonuses (percent) the calculators apply for this setup.
             * Reactions get the rig bonus only and no time bonus.
             *
             * @var array{me: float, te: float}
             */
            'effective_bonuses' => $s->activity_type === 'reaction'
                ? ['me' => StructureBonuses::reactionMe((string) $s->rig_me, (string) $s->system_security), 'te' => 0.0]
                : [
                    'me' => StructureBonuses::manufacturingMe((string) $s->structure_type, (string) $s->rig_me, (string) $s->system_security),
                    'te' => StructureBonuses::manufacturingTe((string) $s->structure_type, (string) $s->rig_te, (string) $s->system_security),
                ],
        ];
    }
}
