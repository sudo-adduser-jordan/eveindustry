<?php

namespace App\Http\Resources\V1\Reference;

use App\Models\SdeStation;
use App\Services\Reference\StationLookupService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An NPC station from the SDE. moon and facility_name are parsed from the
 * name ("Jita IV - Moon 4 - Caldari Navy Assembly Plant").
 *
 * @mixin SdeStation
 */
class StationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $parsed = StationLookupService::parseName((string) $this->station_name);

        return [
            'station_id' => (int) $this->station_id,
            'station_name' => (string) $this->station_name,
            /** @var string|null e.g. "Moon 4"; null for stations not at a moon. */
            'moon' => $parsed['moon'],
            'facility_name' => $parsed['facility_name'],
            'solar_system_id' => (int) $this->solar_system_id,
            /** @var int|null */
            'region_id' => $this->region_id,
            /** @var int|null */
            'station_type_id' => $this->station_type_id,
            /** Unrounded security status of the station's system. */
            'security' => (float) ($this->security ?? 0),
        ];
    }
}
