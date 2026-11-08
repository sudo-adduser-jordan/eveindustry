<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material type with its quantity summed over all assets.
 */
class AssetMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'type_id' => (int) $i['type_id'],
            'type_name' => (string) $i['type_name'],
            'quantity' => (int) $i['quantity'],
            /** Market price per unit, in ISK; 0 when unpriced. */
            'unit_price' => (float) $i['unit_price'],
            'total_value' => (float) $i['total_value'],
        ];
    }
}
