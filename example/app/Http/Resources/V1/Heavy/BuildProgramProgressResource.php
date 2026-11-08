<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Progress of a build program against the stock on hand.
 *
 * Wraps the array from BuildProgramProgressCalculator::summarise() plus the
 * names of the characters whose assets were counted.
 */
class BuildProgramProgressResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $p */
        $p = $this->resource;

        return [
            /** Characters whose assets were counted: all of the user's for a personal program, the director for a corporate one. */
            'character_names' => array_values($p['character_names']),
            /** Percent, 0 to 100. */
            'overall_progress' => (float) $p['overall_progress'],
            /** @var list<ProgressStepResource> The program's steps in order, with their sub-steps nested in `children`. */
            'steps' => ProgressStepResource::collection($p['steps'])->resolve($request),
            /** @var list<ProgressBlueprintResource> */
            'required_blueprints' => ProgressBlueprintResource::collection($p['required_blueprints'])->resolve($request),
            /** @var list<ProgressSupplyResource> Raw materials still to acquire. */
            'base_materials' => ProgressSupplyResource::collection($p['base_materials'])->resolve($request),
            /** @var list<ProgressSupplyResource> Fuel blocks to buy. */
            'fuel_blocks' => ProgressSupplyResource::collection($p['fuel_blocks'])->resolve($request),
            /** @var list<ProgressReactionResource> */
            'reactions_summary' => ProgressReactionResource::collection($p['reactions_summary'])->resolve($request),
            /** @var list<ProgressSectionResource> Progress per merged section; empty for a program without sections. */
            'sections' => ProgressSectionResource::collection($p['sections'] ?? [])->resolve($request),
        ];
    }
}
