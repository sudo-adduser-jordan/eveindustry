<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ship group (Frigate, Cruiser, ...) with the ships the user owns in it.
 */
class AssetShipGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $g = $this->resource;

        return [
            'group_name' => (string) $g['group_name'],
            'count' => (int) $g['count'],
            /** @var list<AssetShipResource> Sorted by type, then custom name. */
            'ships' => AssetShipResource::collection($g['ships'])->resolve($request),
        ];
    }
}
