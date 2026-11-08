<?php

namespace App\Http\Resources\V1\Programs;

use App\Models\CorporationBuildProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A corporate build program with its nested steps.
 *
 * @mixin CorporationBuildProgram
 */
class CorporationBuildProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => $this->id,
            'corporation_id' => $this->corporation_id,
            'created_by_user_id' => $this->created_by_user_id,
            'name' => $this->name,
            /** `manufacturing` or `reaction`. */
            'program_type' => $this->program_type,
            't2_type_id' => $this->t2_type_id,
            't2_type_name' => $this->t2_type_name,
            /** Nested step tree: each step has `children` for the materials it needs built. */
            'steps' => $this->steps ?? [],
            /** Calculator settings, including `input_location_ids`, `output_location_ids` and `sections`. */
            'settings' => (object) ($this->settings ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
