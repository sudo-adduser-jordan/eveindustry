<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A hangar location with the items in it worth more reprocessed than sold.
 * Values are in ISK.
 */
class ReprocessingLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $l = $this->resource;

        return [
            'location_id' => (int) $l['location_id'],
            'location_name' => (string) $l['location_name'],
            /** @var list<ReprocessingItemResource> Most profitable first. */
            'items' => ReprocessingItemResource::collection($l['items'])->resolve($request),
            'total_market_value' => (float) $l['total_market_value'],
            'total_reprocess_value' => (float) $l['total_reprocess_value'],
            'total_profit' => (float) $l['total_profit'],
        ];
    }
}
