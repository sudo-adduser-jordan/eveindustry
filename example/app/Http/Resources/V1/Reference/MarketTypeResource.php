<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published type that is sold on the market (search hit or market group item).
 */
class MarketTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type_id' => (int) $this->resource['type_id'],
            'name' => (string) $this->resource['name'],
            'market_group_id' => isset($this->resource['market_group_id']) ? (int) $this->resource['market_group_id'] : null,
            'icon_url' => (string) $this->resource['icon_url'],
        ];
    }
}
