<?php

namespace App\Http\Resources\V1\Heavy;

use App\Models\MoonScanSystem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A system the corporation has scanned.
 *
 * @mixin MoonScanSystem
 */
class MoonScanSystemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MoonScanSystem $s */
        $s = $this->resource;

        return [
            'name' => $s->system_name,
            /** @var int */
            'region_id' => $s->region_id,
            'pricing_mode' => $s->pricing_mode,
            /** ISO 8601. */
            'last_scanned_at' => $s->last_scanned_at?->toIso8601String(),
        ];
    }
}
