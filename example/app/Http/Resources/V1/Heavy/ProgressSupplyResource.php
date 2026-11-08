<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A raw material or fuel block to acquire for a build program.
 */
class ProgressSupplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'type_id' => (int) $m['type_id'],
            'name' => (string) $m['name'],
            'quantity' => (int) $m['quantity'],
            'available' => (int) $m['available'],
            /** Material group, "Fuel Blocks", or "Other". */
            'group_name' => (string) ($m['group_name'] ?? 'Other'),
            /** Packaged volume per unit, m3. */
            'volume' => (float) ($m['volume'] ?? 0),
        ];
    }
}
