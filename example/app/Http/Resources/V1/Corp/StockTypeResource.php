<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A published type matched by the stock manager search.
 */
class StockTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type_id' => (int) $this->resource['type_id'],
            'name' => (string) $this->resource['name'],
            'icon_url' => (string) $this->resource['icon_url'],
        ];
    }
}
