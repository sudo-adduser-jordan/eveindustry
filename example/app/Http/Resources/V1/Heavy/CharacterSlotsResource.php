<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One character's slots from IndustryService::getCharacterSlots().
 */
class CharacterSlotsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $skills = $c['skills'] ?? [];

        return [
            'character_id' => (int) $c['character_id'],
            'character_name' => (string) $c['character_name'],
            'portrait_url' => $c['portrait_url'] ?? null,
            'corporation_name' => $c['corporation_name'] ?? null,
            'manufacturing' => new SlotGroupResource($c['manufacturing']),
            /** Research, copying and invention share these slots. */
            'research' => new SlotGroupResource($c['research']),
            'reactions' => new SlotGroupResource($c['reactions']),
            /** @var list<array{job_id: int, activity_id: int, end_date: string, minutes_remaining: int}> Jobs ending within the hour. */
            'completing_soon' => array_map(fn (array $j) => [
                'job_id' => (int) $j['job_id'],
                'activity_id' => (int) $j['activity_id'],
                'end_date' => (string) $j['end_date'],
                'minutes_remaining' => (int) $j['minutes_remaining'],
            ], $c['completing_soon'] ?? []),
            /** Slot skill levels. */
            'skills' => [
                'mass_production' => (int) ($skills['mass_production'] ?? 0),
                'adv_mass_production' => (int) ($skills['adv_mass_production'] ?? 0),
                'laboratory_operation' => (int) ($skills['laboratory_operation'] ?? 0),
                'adv_laboratory_operation' => (int) ($skills['adv_laboratory_operation'] ?? 0),
                'mass_reactions' => (int) ($skills['mass_reactions'] ?? 0),
                'adv_mass_reactions' => (int) ($skills['adv_mass_reactions'] ?? 0),
            ],
        ];
    }
}
