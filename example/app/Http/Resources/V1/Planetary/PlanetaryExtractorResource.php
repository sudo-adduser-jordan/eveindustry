<?php

namespace App\Http\Resources\V1\Planetary;

use App\Services\EsiService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An extractor control unit and the resource it is pulling.
 */
class PlanetaryExtractorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $e = $this->resource;

        return [
            'product_type_id' => (int) $e['product_type_id'],
            'product_name' => (string) $e['product_name'],
            'icon_url' => EsiService::getTypeImageUrl((int) $e['product_type_id'], 'icon', 32),
            /** Units per hour at the start of a program (before decay). */
            'per_hour' => (float) $e['per_hour'],
            'is_active' => (bool) $e['is_active'],
            /** When the running program ends (ISO 8601); null when never installed. */
            'install_time' => $e['install_time'] ?? null,
            'expiry_time' => $e['expiry_time'] ?? null,
        ];
    }
}
