<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One asset from AssetService::searchAssets(), with the items inside it
 * (cargo, container contents) nested as children.
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'item_id' => (int) $a['item_id'],
            'type_id' => (int) $a['type_id'],
            'type_name' => (string) $a['type_name'],
            /** @var string|null Custom name of a ship or container. */
            'name' => $a['name'] ?? null,
            'quantity' => (int) ($a['quantity'] ?? 0),
            'location_id' => (int) ($a['location_id'] ?? 0),
            'location_name' => (string) ($a['location_name'] ?? ''),
            'location_flag' => (string) ($a['location_flag'] ?? ''),
            'character_id' => (int) ($a['character_id'] ?? 0),
            'character_name' => (string) ($a['character_name'] ?? ''),
            /** @var string "personal" or "corporate". */
            'owner_type' => (string) ($a['owner_type'] ?? ''),
            'is_singleton' => (bool) ($a['is_singleton'] ?? false),
            /** @var string|null Set on corporate assets only. */
            'corporation_name' => $a['corporation_name'] ?? null,
            /** @var int|null The containing item, when nested. */
            'parent_item_id' => isset($a['parent_item_id']) ? (int) $a['parent_item_id'] : null,
            /** @var list<AssetResource> */
            'children' => self::collection($a['children'] ?? [])->resolve($request),
            'children_count' => (int) ($a['children_count'] ?? count($a['children'] ?? [])),
            'has_children' => (bool) ($a['has_children'] ?? ! empty($a['children'])),
        ];
    }
}
