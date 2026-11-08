<?php

namespace App\Http\Resources\V1\Programs;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One step of a build program: an item to build or buy, with the materials it
 * takes and the sub-steps for materials that are built too.
 */
class BuildProgramStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'type_id' => (int) ($s['type_id'] ?? 0),
            'name' => (string) ($s['name'] ?? 'Unknown'),
            'quantity_required' => (int) ($s['quantity_required'] ?? 0),
            'runs_needed' => isset($s['runs_needed']) ? (int) $s['runs_needed'] : null,
            /** `manufacturing` or `reaction`; null for a bought item. */
            'activity_type' => $s['activity_type'] ?? null,
            'blueprint_type_id' => isset($s['blueprint_type_id']) ? (int) $s['blueprint_type_id'] : null,
            /** @var list<BuildProgramMaterialResource> */
            'materials' => BuildProgramMaterialResource::collection($s['materials'] ?? [])->resolve($request),
            /** @var list<array<string, mixed>> Sub-steps, each shaped like this one. */
            'children' => array_values($s['children'] ?? []),
        ];
    }
}
