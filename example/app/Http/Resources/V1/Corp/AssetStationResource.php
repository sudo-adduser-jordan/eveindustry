<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One station or structure holding assets, with its top-level items (items
 * inside ships and containers are nested under each item's `children`).
 */
class AssetStationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'location_id' => (int) $s['location_id'],
            'location_name' => (string) $s['location_name'],
            /** @var list<AssetResource> */
            'items' => AssetResource::collection($s['items'])->resolve($request),
            'item_count' => (int) ($s['item_count'] ?? count($s['items'])),
        ];
    }
}
