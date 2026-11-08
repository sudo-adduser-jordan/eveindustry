<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One industry activity of a blueprint or reaction formula: inputs, outputs
 * and base time for a single run, before ME/TE or structure bonuses.
 *
 * Built from ['type_id' => int, 'activity' => string, 'materials' => [...],
 * 'products' => [...], 'time' => int].
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Blueprint or reaction formula type id. */
            'type_id' => (int) $this->resource['type_id'],
            /** "manufacturing" or "reaction". */
            'activity' => (string) $this->resource['activity'],
            /** Base duration of one run, in seconds. */
            'time' => (int) ($this->resource['time'] ?? 0),
            /** @var list<array{type_id: int, name: string|null, quantity: int}> */
            'materials' => self::items($this->resource['materials'] ?? []),
            /** @var list<array{type_id: int, name: string|null, quantity: int}> */
            'products' => self::items($this->resource['products'] ?? []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array{type_id: int, name: string|null, quantity: int}>
     */
    public static function items(array $items): array
    {
        return array_values(array_map(fn (array $item) => [
            'type_id' => (int) ($item['type_id'] ?? $item['typeid'] ?? 0),
            'name' => isset($item['name']) ? (string) $item['name'] : null,
            'quantity' => (int) ($item['quantity'] ?? 0),
        ], $items));
    }
}
