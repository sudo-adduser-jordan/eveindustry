<?php

namespace App\Http\Resources\V1\Corp;

use App\Services\AbyssalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One changed attribute of an abyssal module, with display strings that use
 * the attribute's unit.
 */
class AbyssalAttributeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $unitId = isset($a['unit_id']) ? (int) $a['unit_id'] : null;

        return [
            'name' => (string) $a['name'],
            'value' => AbyssalService::formatAttributeValue((float) $a['value'], $unitId),
            /** The difference from the base type, signed, with the unit. Empty for booleans. */
            'change' => AbyssalService::formatAttributeChange((float) $a['value'], (float) $a['base_value'], $unitId),
            /** Percentage change from the base value; null when the base is 0. */
            'pct_change' => isset($a['pct_change']) ? round((float) $a['pct_change'], 2) : null,
            'is_good' => (bool) $a['is_good'],
        ];
    }
}
