<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A material held by a can-produce source.
 */
class CanProduceStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type_id' => (int) $this->resource['type_id'],
            'name' => (string) $this->resource['name'],
            'quantity' => (int) $this->resource['quantity'],
        ];
    }
}
