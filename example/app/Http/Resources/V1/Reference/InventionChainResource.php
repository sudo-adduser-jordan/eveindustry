<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Everything needed to plan inventing a T2 item: the T2 and T1 blueprints'
 * manufacturing activities, the T1 blueprint's invention inputs and the
 * skills that affect the invention chance. Built from
 * InventionPlannerService::resolveInventionChain().
 */
class InventionChainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $chain = $this->resource;

        return [
            't2_item_type_id' => (int) $chain['t2_item_type_id'],
            't2_blueprint_type_id' => (int) $chain['t2_blueprint_type_id'],
            /** @var array{time: int, materials: list<array{type_id: int, name: string|null, quantity: int}>, products: list<array{type_id: int, name: string|null, quantity: int}>} */
            't2_manufacturing' => self::activity($chain['t2_manufacturing']),
            't1_item_type_id' => (int) $chain['t1_item_type_id'],
            /** @var string|null */
            't1_item_name' => $chain['t1_item_name'],
            't1_blueprint_type_id' => (int) $chain['t1_blueprint_type_id'],
            /** @var array{time: int, materials: list<array{type_id: int, name: string|null, quantity: int}>, products: list<array{type_id: int, name: string|null, quantity: int}>}|null */
            't1_manufacturing' => $chain['t1_manufacturing'] === null ? null : self::activity($chain['t1_manufacturing']),
            /** @var list<array{type_id: int, name: string, quantity: int, skill_id: int|null, skill_name: string|null}> */
            'datacores' => array_values(array_map(fn (array $d) => [
                'type_id' => (int) $d['type_id'],
                'name' => (string) $d['name'],
                'quantity' => (int) $d['quantity'],
                'skill_id' => $d['skill_id'] === null ? null : (int) $d['skill_id'],
                'skill_name' => $d['skill_name'],
            ], $chain['datacores'])),
            /** Base invention time per run, in seconds. */
            'invention_time' => (int) $chain['invention_time'],
            /** Base success chance before skills and decryptors (0 to 1). */
            'base_probability' => (float) $chain['base_probability'],
            /** Runs on an invented T2 blueprint copy before decryptors. */
            'base_runs' => (int) $chain['base_runs'],
            /** @var list<int> */
            'science_skill_ids' => array_map('intval', $chain['science_skill_ids']),
            /** @var int|null */
            'encryption_skill_id' => $chain['encryption_skill_id'],
            /** @var string|null */
            'encryption_skill_name' => $chain['encryption_skill_name'],
            /** @var int|null */
            'science1_skill_id' => $chain['science1_skill_id'],
            /** @var string|null */
            'science1_skill_name' => $chain['science1_skill_name'],
            /** @var int|null */
            'science2_skill_id' => $chain['science2_skill_id'],
            /** @var string|null */
            'science2_skill_name' => $chain['science2_skill_name'],
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array{time: int, materials: list<array{type_id: int, name: string|null, quantity: int}>, products: list<array{type_id: int, name: string|null, quantity: int}>}
     */
    private static function activity(array $activity): array
    {
        return [
            'time' => (int) ($activity['time'] ?? 0),
            'materials' => ActivityResource::items($activity['materials'] ?? []),
            'products' => ActivityResource::items($activity['products'] ?? []),
        ];
    }
}
