<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One material a buildable item needs, against what the source holds.
 */
class CanProduceMaterialResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'type_id' => (int) $m['type_id'],
            'name' => (string) $m['name'],
            /** Per run, after the blueprint's material efficiency. */
            'required_per_run' => (int) $m['required_per_run'],
            'available' => (int) $m['available'],
            /** Runs this material alone would allow. */
            'runs_possible' => (int) $m['runs_possible'],
        ];
    }
}
