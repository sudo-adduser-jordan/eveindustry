<?php

namespace App\Http\Resources\V1\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical sell price for one type, from MarketService::getSellPricesWithSource().
 */
class MarketPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type_id' => (int) $this->resource['type_id'],
            /** ISK per unit; 0 when no source has a price. */
            'price' => (float) $this->resource['price'],
            /** orders (5% sell average), fallback (market_prices) or none. */
            'source' => (string) $this->resource['source'],
        ];
    }
}
