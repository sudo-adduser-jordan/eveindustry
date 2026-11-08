<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A running industry job, from IndustryService::getDetailedJobsByActivity().
 */
class IndustryJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $j = $this->resource;

        return [
            'job_id' => (int) $j['job_id'],
            'activity_id' => (int) $j['activity_id'],
            'activity_name' => (string) $j['activity_name'],
            'blueprint_type_id' => isset($j['blueprint_type_id']) ? (int) $j['blueprint_type_id'] : null,
            'product_type_id' => isset($j['product_type_id']) ? (int) $j['product_type_id'] : null,
            'item_name' => (string) $j['item_name'],
            'item_image_url' => $j['item_image_url'] ?? null,
            'runs' => (int) $j['runs'],
            'licensed_runs' => isset($j['licensed_runs']) ? (int) $j['licensed_runs'] : null,
            'start_date' => (string) $j['start_date'],
            'end_date' => (string) $j['end_date'],
            /** 0–100. */
            'progress_pct' => (int) $j['progress_pct'],
            /** Like "1d 4h 12m". */
            'time_remaining' => (string) $j['time_remaining'],
            'seconds_remaining' => (int) $j['seconds_remaining'],
            'ready_for_delivery' => (bool) $j['ready_for_delivery'],
            'status' => (string) $j['status'],
            /** Invention success chance in percent. */
            'probability_pct' => isset($j['probability_pct']) ? (float) $j['probability_pct'] : null,
            'probability_label' => $j['probability_label'] ?? null,
        ];
    }
}
