<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A market group with its path from the root, its child groups and the
 * published items listed directly in it.
 */
class MarketGroupContentsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = $this->resource['group'];
        $groupId = (int) $group['market_group_id'];

        return [
            'market_group_id' => $groupId,
            'name' => (string) $group['name'],
            /** @var string|null */
            'description' => $group['description'] ?? null,
            'parent_group_id' => $group['parent_group_id'] === null ? null : (int) $group['parent_group_id'],
            /** @var list<array{market_group_id: int, name: string}> Root first, this group last. */
            'breadcrumbs' => array_map(fn (array $crumb) => [
                'market_group_id' => (int) $crumb['market_group_id'],
                'name' => (string) $crumb['name'],
            ], $this->resource['breadcrumbs']),
            'children' => MarketGroupResource::collection($this->resource['children']),
            /** Published items in this group, by name. */
            'items' => MarketTypeResource::collection(array_map(
                fn (array $item) => $item + ['market_group_id' => $groupId],
                $this->resource['items'],
            )),
        ];
    }
}
