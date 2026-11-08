<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Runs and volumes of one reaction in a build program.
 */
class ProgressReactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'type_id' => (int) $r['type_id'],
            'name' => (string) $r['name'],
            'blueprint_type_id' => (int) $r['blueprint_type_id'],
            'total_runs' => (int) $r['total_runs'],
            /** Units the program needs. */
            'output_qty' => (int) $r['output_qty'],
            /** Units already held. */
            'available' => (int) $r['available'],
            'net_output_qty' => (int) $r['net_output_qty'],
            /** Runs still to start; 0 when done. */
            'net_runs' => (int) $r['net_runs'],
            'input_volume' => (float) $r['input_volume'],
            'output_volume' => (float) $r['output_volume'],
            /** @var list<ProgressReactionInputResource> */
            'inputs' => ProgressReactionInputResource::collection($r['inputs'])->resolve($request),
        ];
    }
}
