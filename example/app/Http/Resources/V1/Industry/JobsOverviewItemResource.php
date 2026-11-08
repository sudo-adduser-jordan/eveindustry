<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the manufacturing and reaction jobs will deliver of one item.
 */
class JobsOverviewItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'type_id' => isset($i['type_id']) ? (int) $i['type_id'] : null,
            'item_name' => (string) $i['item_name'],
            'item_image_url' => $i['item_image_url'] ?? null,
            'total_runs' => (int) $i['total_runs'],
            'total_expected_output' => (int) $i['total_expected_output'],
            'job_count' => (int) $i['job_count'],
            /** Output at the region sell price; null when unpriced. */
            'estimated_value' => isset($i['estimated_value']) ? (float) $i['estimated_value'] : null,
        ];
    }
}
