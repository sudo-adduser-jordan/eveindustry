<?php

namespace App\Http\Resources\V1\Programs;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A merged program's original program: it owns the next `step_count` top-level steps.
 */
class BuildProgramSectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => (string) $this->resource['name'],
            'step_count' => (int) $this->resource['step_count'],
        ];
    }
}
