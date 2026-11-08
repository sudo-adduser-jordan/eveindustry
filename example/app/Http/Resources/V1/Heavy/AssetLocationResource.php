<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A station or structure that holds assets, with its solar system.
 */
class AssetLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{location_id: int, name: string, solar_system_id: ?int, solar_system_name: ?string} $l */
        $l = $this->resource;

        return [
            'location_id' => (int) $l['location_id'],
            'name' => (string) $l['name'],
            'solar_system_id' => $l['solar_system_id'] !== null ? (int) $l['solar_system_id'] : null,
            'solar_system_name' => $l['solar_system_name'],
        ];
    }
}
