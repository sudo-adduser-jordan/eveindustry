<?php

namespace App\Http\Resources\V1\Corp;

use App\Models\CorporationBuildProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A corporate build program with its step tree, for the detail page.
 *
 * @mixin CorporationBuildProgram
 */
class CorporateBuildProgramDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = $this->settings ?? [];

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            /** `manufacturing` or `reaction`. */
            'program_type' => (string) $this->program_type,
            /** @var int|null */
            't2_type_id' => $this->t2_type_id,
            't2_type_name' => $this->t2_type_name,
            /**
             * Top-level steps in section order. A step has `children` of the same shape.
             *
             * @var list<array{type_id: int, name: string, quantity_required: int, runs_needed: int|null, activity_type: string|null, materials: list<array{type_id: int, name: string, quantity: int}>, children: list<array<string, mixed>>}>
             */
            'steps' => array_map(fn (array $step) => $this->step($step), array_values($this->steps ?? [])),
            /** @var list<array{name: string, step_count: int}> Merged programs group their steps into named sections. */
            'sections' => array_map(fn (array $s) => [
                'name' => (string) ($s['name'] ?? ''),
                'step_count' => (int) ($s['step_count'] ?? 0),
            ], array_values($settings['sections'] ?? [])),
            /** @var list<int> */
            'input_location_ids' => array_map('intval', $settings['input_location_ids'] ?? []),
            /** @var list<int> */
            'output_location_ids' => array_map('intval', $settings['output_location_ids'] ?? []),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private function step(array $step): array
    {
        return [
            'type_id' => (int) ($step['type_id'] ?? 0),
            'name' => (string) ($step['name'] ?? 'Unknown'),
            'quantity_required' => (int) ($step['quantity_required'] ?? 0),
            'runs_needed' => isset($step['runs_needed']) ? (int) $step['runs_needed'] : null,
            'activity_type' => $step['activity_type'] ?? null,
            'materials' => array_map(fn (array $m) => [
                'type_id' => (int) ($m['type_id'] ?? 0),
                'name' => (string) ($m['name'] ?? 'Unknown'),
                'quantity' => (int) ($m['adjusted_quantity'] ?? $m['quantity'] ?? 0),
            ], array_values($step['materials'] ?? [])),
            'children' => array_map(fn (array $c) => $this->step($c), array_values($step['children'] ?? [])),
        ];
    }
}
