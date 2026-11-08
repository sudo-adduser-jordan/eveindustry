<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Items worth more reprocessed than sold, per character and location, with totals.
 */
class ReprocessingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{characters: list<array<string, mixed>>, totalProfit: int|float, totalCharacters: int, totalItems: int} $r */
        $r = $this->resource;

        return [
            /** @var list<ReprocessingCharacterResource> */
            'characters' => ReprocessingCharacterResource::collection($r['characters'])->resolve($request),
            'totals' => [
                'profit' => (float) $r['totalProfit'],
                'characters' => (int) $r['totalCharacters'],
                'items' => (int) $r['totalItems'],
            ],
        ];
    }
}
