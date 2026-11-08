<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A market group with the number of child groups under it.
 */
class MarketGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'market_group_id' => (int) $this->resource['market_group_id'],
            'name' => (string) $this->resource['name'],
            /** @var string|null */
            'description' => $this->resource['description'] ?? null,
            /** True when items are listed directly in this group. */
            'has_types' => (bool) $this->resource['has_types'],
            'child_count' => (int) $this->resource['child_count'],
        ];
    }
}
