<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One character's reprocessing opportunities, from ReprocessingService.
 */
class ReprocessingCharacterResource extends JsonResource
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
            /** Reprocessing yield in percent. */
            'yield_pct' => (float) $c['yield_pct'],
            'skills' => [
                'reprocessing' => (int) ($skills['reprocessing'] ?? 0),
                'reprocessing_efficiency' => (int) ($skills['reprocessing_efficiency'] ?? 0),
                'scrapmetal_processing' => (int) ($skills['scrapmetal_processing'] ?? 0),
            ],
            /** @var list<ReprocessingLocationResource> Most profitable first. */
            'locations' => ReprocessingLocationResource::collection($c['locations'] ?? [])->resolve($request),
            'total_profit' => (float) $c['total_profit'],
        ];
    }
}
