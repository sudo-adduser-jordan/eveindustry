<?php

namespace App\Http\Resources\V1\Reference;

use App\Models\SdeSolarSystem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A solar system from the SDE.
 *
 * @mixin SdeSolarSystem
 */
class SolarSystemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'solar_system_id' => (int) $this->solar_system_id,
            'solar_system_name' => (string) $this->solar_system_name,
            /** @var int|null */
            'constellation_id' => $this->constellation_id,
            /** @var int|null */
            'region_id' => $this->region_id,
            /** Unrounded security status, e.g. 0.9459 (the game shows it rounded to 1 dp). */
            'security' => (float) ($this->security ?? 0),
        ];
    }
}
