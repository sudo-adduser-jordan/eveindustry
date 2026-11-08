<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A job ready (or within a minute of ready) for collection, from
 * IndustryService::getReadyForCollectionJobsForUser().
 */
class ReadyJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $j = $this->resource;

        return [
            'job_id' => (int) $j['job_id'],
            'character_id' => (int) $j['character_id'],
            'character_name' => (string) $j['character_name'],
            'activity_id' => isset($j['activity_id']) ? (int) $j['activity_id'] : null,
            'activity_name' => (string) $j['activity_name'],
            'item_name' => (string) $j['item_name'],
            'item_image_url' => $j['item_image_url'] ?? null,
            'end_date' => (string) $j['end_date'],
            /** Station, structure or system name. */
            'location' => (string) $j['location'],
            'runs' => (int) $j['runs'],
            'probability_pct' => isset($j['probability_pct']) ? (float) $j['probability_pct'] : null,
            'probability_label' => $j['probability_label'] ?? null,
        ];
    }
}
