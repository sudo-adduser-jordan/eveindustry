<?php

namespace App\Http\Resources\V1\Programs;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A material one build program step consumes.
 */
class BuildProgramMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'type_id' => (int) ($m['type_id'] ?? 0),
            'name' => (string) ($m['name'] ?? 'Unknown'),
            /** Needed for the whole step, after material efficiency (`adjusted_quantity` when set, else `quantity`). */
            'quantity' => (int) ($m['adjusted_quantity'] ?? $m['quantity'] ?? 0),
        ];
    }
}
