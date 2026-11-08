<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The solar system a character is in right now.
 */
class LocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{solar_system_id: int, solar_system_name: string, security: ?float, security_class: ?string, region_id: ?int} $l */
        $l = $this->resource;

        return [
            'solar_system_id' => (int) $l['solar_system_id'],
            'solar_system_name' => $l['solar_system_name'],
            'security' => $l['security'],
            'security_class' => $l['security_class'],
            'region_id' => $l['region_id'],
        ];
    }
}
