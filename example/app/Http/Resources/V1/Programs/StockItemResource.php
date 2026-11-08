<?php

namespace App\Http\Resources\V1\Programs;

use App\Models\IndustryStockItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An industry stock manager item: a type and how many the user wants in stock.
 *
 * @mixin IndustryStockItem
 */
class StockItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type_id' => $this->type_id,
            'type_name' => $this->type_name,
            'desired_quantity' => (int) $this->desired_quantity,
        ];
    }
}
