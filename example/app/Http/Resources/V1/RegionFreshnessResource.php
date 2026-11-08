<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Data age for one trade region, built from MarketFreshnessService::regions().
 */
class RegionFreshnessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'region_id' => (int) $this->resource['region_id'],
            'region_name' => (string) $this->resource['region_name'],
            /** @var string|null Last regional order fetch (ISO 8601 UTC). */
            'orders_as_of' => $this->resource['orders_as_of'],
            /** @var string|null Last manufacturing cost precompute (ISO 8601 UTC). */
            'costs_as_of' => $this->resource['costs_as_of'],
            /** @var string|null Last ESI global price fetch (ISO 8601 UTC). */
            'global_as_of' => $this->resource['global_as_of'],
            /** True when any source is missing or past its threshold. */
            'stale' => (bool) $this->resource['stale'],
        ];
    }
}
