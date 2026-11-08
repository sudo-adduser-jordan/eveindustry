<?php

namespace App\Http\Resources\V1\Programs;

use App\Models\BuildProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A personal build program with its nested steps.
 *
 * @mixin BuildProgram
 */
class BuildProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => $this->id,
            'name' => $this->name,
            /** `manufacturing` or `reaction`. */
            'program_type' => $this->program_type,
            't2_type_id' => $this->t2_type_id,
            't2_type_name' => $this->t2_type_name,
            /** Nested step tree: each step has `children` for the materials it needs built. */
            'steps' => $this->steps ?? [],
            /** Calculator settings, including `location_ids` (stock filter) and `sections` (merged programs). */
            'settings' => (object) ($this->settings ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
