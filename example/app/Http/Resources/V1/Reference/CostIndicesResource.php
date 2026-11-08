<?php

namespace App\Http\Resources\V1\Reference;

use App\Services\Reference\SystemCostIndexService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * ESI industry cost indices for one system. Built from
 * ['solar_system_id' => int, 'indices' => [activity => float]].
 */
class CostIndicesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $indices = $this->resource['indices'];
        $byActivity = [];
        foreach (SystemCostIndexService::ACTIVITIES as $activity) {
            $byActivity[$activity] = (float) ($indices[$activity] ?? 0.0);
        }

        return [
            'solar_system_id' => (int) $this->resource['solar_system_id'],
            /**
             * Cost index per activity (a fraction of estimated item value; 0 when ESI lists none).
             *
             * @var array{manufacturing: float, researching_time_efficiency: float, researching_material_efficiency: float, copying: float, invention: float, reaction: float}
             */
            'cost_indices' => $byActivity,
        ];
    }
}
