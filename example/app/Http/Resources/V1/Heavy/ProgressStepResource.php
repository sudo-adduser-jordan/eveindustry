<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One build program step against the stock on hand.
 */
class ProgressStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'type_id' => (int) $s['type_id'],
            'name' => (string) $s['name'],
            'quantity_required' => (int) $s['quantity_required'],
            'available' => (int) $s['available'],
            /** Percent, 0 to 100. */
            'progress' => (float) $s['progress'],
            'has_enough' => (bool) $s['has_enough'],
            /** @var list<ProgressMaterialResource> */
            'materials' => ProgressMaterialResource::collection($s['materials'])->resolve($request),
            /** @var list<array<string, mixed>> Sub-steps, each shaped like this one. */
            'children' => array_values($s['children']),
        ];
    }
}
