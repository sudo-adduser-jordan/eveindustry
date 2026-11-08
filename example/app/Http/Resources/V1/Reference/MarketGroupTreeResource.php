<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A node of the market group tree; children nest with the same shape.
 */
class MarketGroupTreeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'market_group_id' => (int) $this->resource['market_group_id'],
            'name' => (string) $this->resource['name'],
            'has_types' => (bool) $this->resource['has_types'],
            /** @var list<array<string, mixed>> Child nodes, same shape. */
            'children' => array_map(
                fn (array $child) => (new self($child))->toArray($request),
                $this->resource['children'] ?? [],
            ),
        ];
    }
}
