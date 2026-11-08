<?php

namespace App\Http\Resources\V1\Corp;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One solar system from AssetService::getSolarSystemGroupedAssets(), with the
 * stations (or structures) in it that hold assets.
 */
class AssetSolarSystemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            /** Null when the system could not be resolved. */
            'solar_system_id' => isset($s['solar_system_id']) ? (int) $s['solar_system_id'] : null,
            'solar_system_name' => (string) $s['solar_system_name'],
            /** @var list<AssetStationResource> */
            'stations' => AssetStationResource::collection($s['stations'])->resolve($request),
        ];
    }
}
