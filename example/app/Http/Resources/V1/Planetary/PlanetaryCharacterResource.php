<?php

namespace App\Http\Resources\V1\Planetary;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One character's planetary colonies.
 */
class PlanetaryCharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'character_id' => (int) $c['character_id'],
            'character_name' => (string) $c['character_name'],
            'portrait_url' => $c['portrait_url'] ?? null,
            /** @var list<PlanetResource> */
            'planets' => PlanetResource::collection($c['planets'] ?? [])->resolve($request),
        ];
    }
}
