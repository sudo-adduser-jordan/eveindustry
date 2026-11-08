<?php

namespace App\Http\Resources\V1\Programs;

use App\Models\BuildProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A personal build program with typed steps and sections.
 *
 * @mixin BuildProgram
 */
class BuildProgramDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            /** `manufacturing` or `reaction`. */
            'program_type' => (string) $this->program_type,
            't2_type_id' => $this->t2_type_id !== null ? (int) $this->t2_type_id : null,
            't2_type_name' => $this->t2_type_name !== null ? (string) $this->t2_type_name : null,
            /** @var list<BuildProgramStepResource> */
            'steps' => BuildProgramStepResource::collection($this->steps ?? [])->resolve($request),
            /** @var list<BuildProgramSectionResource> Empty unless the program was merged from others. */
            'sections' => BuildProgramSectionResource::collection($this->settings['sections'] ?? [])->resolve($request),
            /** @var list<int> Saved stock location filter; empty means all locations. */
            'location_ids' => array_values(array_map('intval', $this->settings['location_ids'] ?? [])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
