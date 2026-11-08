<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One kind of industry slot for a character, with its running jobs.
 */
class SlotGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $g = $this->resource;

        return [
            'total' => (int) $g['total'],
            'active' => (int) $g['active'],
            'available' => (int) $g['available'],
            /** @var list<IndustryJobResource> Soonest to finish first. */
            'jobs' => IndustryJobResource::collection($g['jobs'] ?? [])->resolve($request),
        ];
    }
}
