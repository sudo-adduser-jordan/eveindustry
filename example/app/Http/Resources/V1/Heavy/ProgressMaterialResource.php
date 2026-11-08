<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A material of one build program step against the stock on hand.
 */
class ProgressMaterialResource extends JsonResource
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
            /** Still needed, scaled down by what is already built. */
            'required' => (int) $m['required'],
            'available' => (int) $m['available'],
            /** Percent, 0 to 100. */
            'progress' => (float) $m['progress'],
            'has_enough' => (bool) $m['has_enough'],
        ];
    }
}
