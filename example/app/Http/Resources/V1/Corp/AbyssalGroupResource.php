<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Abyssal modules sharing a source (base) type, best roll first.
 */
class AbyssalGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $g = $this->resource;

        return [
            'source_type_id' => (int) $g['source_type_id'],
            'source_type_name' => (string) $g['source_type_name'],
            /** @var list<AbyssalItemResource> */
            'items' => AbyssalItemResource::collection($g['items'])->resolve($request),
        ];
    }
}
