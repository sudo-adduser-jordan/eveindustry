<?php

namespace App\Http\Resources\V1\Corp;

use App\Models\CorporationBuildProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A corporate build program as listed on the overview: no steps, just their
 * count and the saved location filters.
 *
 * @mixin CorporationBuildProgram
 */
class CorporateBuildProgramSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->settings ?? [];

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            /** `manufacturing` or `reaction`. */
            'program_type' => (string) $this->program_type,
            /** @var int|null */
            't2_type_id' => $this->t2_type_id,
            't2_type_name' => $this->t2_type_name,
            'step_count' => count($this->steps ?? []),
            /** @var list<int> Where materials are counted; empty counts every location. */
            'input_location_ids' => array_map('intval', $settings['input_location_ids'] ?? []),
            /** @var list<int> Where finished products are counted; empty counts every location. */
            'output_location_ids' => array_map('intval', $settings['output_location_ids'] ?? []),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
