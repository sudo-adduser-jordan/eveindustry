<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material group (minerals, PI, ...) from AssetService::getMaterialsForUser().
 */
class AssetMaterialGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $g = $this->resource;

        return [
            'name' => (string) $g['name'],
            /** Sum of the items' total values, in ISK. */
            'total' => (float) $g['total'],
            /** @var list<AssetMaterialResource> Sorted by name. */
            'items' => AssetMaterialResource::collection($g['items'])->resolve($request),
        ];
    }
}
