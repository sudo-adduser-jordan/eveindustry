<?php

namespace App\Http\Resources\V1\Calculators;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How many of one type the user owns. The resource is
 * ['type_id' => int, 'quantity' => int].
 */
class StockLevelResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return [
            'type_id' => (int) $this->resource['type_id'],
            'quantity' => (int) $this->resource['quantity'],
        ];
    }
}
