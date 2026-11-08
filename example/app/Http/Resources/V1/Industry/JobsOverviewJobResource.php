<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One active job in the jobs overview, from IndustryService::getJobsOverviewForUser().
 */
class JobsOverviewJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $j = $this->resource;

        return [
            'job_id' => (int) $j['job_id'],
            'type_id' => isset($j['type_id']) ? (int) $j['type_id'] : null,
            'item_name' => (string) $j['item_name'],
            'item_image_url' => $j['item_image_url'] ?? null,
            'character_name' => (string) $j['character_name'],
            'location' => (string) $j['location'],
            'runs' => (int) $j['runs'],
            /** Items the job delivers (manufacturing and reactions); runs for the rest. */
            'expected_output' => (float) $j['expected_output'],
            /** Output at the region sell price; null for other activities or when unpriced. */
            'estimated_value' => isset($j['estimated_value']) ? (float) $j['estimated_value'] : null,
            /** Like "1d 4h 12m". */
            'time_remaining' => (string) $j['time_remaining'],
            'seconds_remaining' => (int) $j['seconds_remaining'],
            'end_date' => (string) $j['end_date'],
            /** 0–100. */
            'progress_pct' => (int) $j['progress_pct'],
            'ready_for_delivery' => (bool) $j['ready_for_delivery'],
        ];
    }
}
