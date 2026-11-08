<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A watched stock item against the corporation hangars, from IndustryStockService::getStockReport().
 */
class StockReportItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => (int) $i['id'],
            'type_id' => (int) $i['type_id'],
            'type_name' => (string) $i['type_name'],
            'desired_quantity' => (int) $i['desired'],
            /** Held in corporation assets; 0 while `meta.warning` is set. */
            'on_hand' => (int) $i['on_hand'],
            'shortfall' => (int) $i['shortfall'],
            'icon_url' => (string) $i['icon_url'],
        ];
    }
}
