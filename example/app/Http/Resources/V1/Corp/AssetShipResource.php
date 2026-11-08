<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ship hull from the asset list.
 */
class AssetShipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'item_id' => (int) $s['item_id'],
            'type_id' => (int) $s['type_id'],
            'type_name' => (string) $s['type_name'],
            /** @var string|null Custom name given in game. */
            'name' => $s['name'] ?? null,
            'location_name' => (string) ($s['location_name'] ?? ''),
            'character_id' => (int) ($s['character_id'] ?? 0),
            'character_name' => (string) ($s['character_name'] ?? ''),
        ];
    }
}
