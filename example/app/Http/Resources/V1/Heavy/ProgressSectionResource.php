<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Progress of one merged section of a build program.
 */
class ProgressSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'name' => (string) $s['name'],
            'step_count' => (int) $s['step_count'],
            /** Percent, 0 to 100. */
            'overall_progress' => (float) $s['overall_progress'],
        ];
    }
}
