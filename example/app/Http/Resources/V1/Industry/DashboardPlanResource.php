<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved reaction or invention plan, by name.
 */
class DashboardPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            /** Invention plans only: the T2 item the plan makes. */
            't2_type_name' => $this->t2_type_name ?? null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
