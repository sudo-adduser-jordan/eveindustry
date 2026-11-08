<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tech I blueprint and whether the user owns it, from
 * BlueprintResearchService::getBlueprintResearchData().
 */
class BlueprintResearchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $b = $this->resource;

        return [
            'type_id' => (int) $b->type_id,
            'name' => (string) $b->name,
            'market_group_id' => $b->market_group_id !== null ? (int) $b->market_group_id : null,
            'market_group_name' => $b->market_group_name,
            'owned' => (bool) $b->owned,
            /** Best material efficiency among owned copies; null when not owned. */
            'me' => $b->me !== null ? (int) $b->me : null,
            /** Best time efficiency among owned copies; null when not owned. */
            'te' => $b->te !== null ? (int) $b->te : null,
            /** @var list<string> Characters holding it. */
            'owner_names' => array_values($b->owner_names),
        ];
    }
}
