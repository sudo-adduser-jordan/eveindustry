<?php

namespace App\Http\Resources\V1\Market;

use App\Models\MarketOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketOrder
 */
class MarketOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'order_id' => (int) $this->order_id,
            'type_id' => (int) $this->type_id,
            'region_id' => (int) $this->region_id,
            'is_buy_order' => (bool) $this->is_buy_order,
            /** ISK per unit. */
            'price' => (float) $this->price,
            'volume_remain' => (int) $this->volume_remain,
            'volume_total' => (int) $this->volume_total,
            'min_volume' => (int) $this->min_volume,
            /** Station or structure id. */
            'location_id' => $this->location_id !== null ? (int) $this->location_id : null,
            /** station, region, solarsystem or a jump count. */
            'range' => $this->range,
            /** Days. */
            'duration' => $this->duration !== null ? (int) $this->duration : null,
            /** When the order was placed (ISO 8601 UTC). */
            'issued' => $this->issued?->utc()->toIso8601ZuluString(),
        ];
    }
}
