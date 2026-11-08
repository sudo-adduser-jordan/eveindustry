<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Industry slots per character and in total, from IndustryService::getAvailabilityForUser(),
 * plus the jobs that are ready (or within a minute of ready) for collection.
 */
class IndustryAvailabilityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{characters: list<array<string, mixed>>, totals: array<string, array<string, int>>, ready_for_collection?: list<array<string, mixed>>} $a */
        $a = $this->resource;

        $totals = fn (string $kind) => [
            'total' => (int) ($a['totals'][$kind]['total'] ?? 0),
            'active' => (int) ($a['totals'][$kind]['active'] ?? 0),
            'available' => (int) ($a['totals'][$kind]['available'] ?? 0),
        ];

        return [
            /** @var list<CharacterSlotsResource> */
            'characters' => CharacterSlotsResource::collection($a['characters'])->resolve($request),
            'totals' => [
                'manufacturing' => $totals('manufacturing'),
                'research' => $totals('research'),
                'reactions' => $totals('reactions'),
            ],
            /** @var list<ReadyJobResource> Soonest first. */
            'ready_for_collection' => ReadyJobResource::collection($a['ready_for_collection'] ?? [])->resolve($request),
        ];
    }
}
