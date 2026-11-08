<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mutated module with the attributes that changed from its base type.
 */
class AbyssalItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'item_id' => (int) $i['item_id'],
            'type_id' => (int) $i['type_id'],
            'type_name' => (string) $i['type_name'],
            'character_id' => (int) $i['character_id'],
            'character_name' => (string) $i['character_name'],
            'location_name' => (string) $i['location_name'],
            /** @var string|null Name of the container or ship holding it. */
            'container_name' => $i['container_name'] ?? null,
            /** @var int|null */
            'mutator_type_id' => $i['mutator_type_id'] ?? null,
            /** Sum of the beneficial minus detrimental percentage changes. */
            'score' => round((float) $i['score'], 1),
            /** @var list<AbyssalAttributeResource> */
            'attributes' => AbyssalAttributeResource::collection($i['attributes'])->resolve($request),
        ];
    }
}
