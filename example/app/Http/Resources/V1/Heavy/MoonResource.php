<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An analysed or stored moon: grade, value and ore breakdown.
 */
class MoonResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $m */
        $m = $this->resource;

        return [
            /** @var int|null Stored result id; null when the moon could not be stored. */
            'id' => $m['id'] ?? null,
            'name' => $m['name'],
            /** @var string|null S, A, B, C or D by the rarity tier of the best ore. */
            'grade' => $m['grade'] ?? null,
            /** @var float ISK value of the moon ore at the chosen pricing. */
            'total_value' => $m['total_value'],
            /** @var list<array{name: string, type_id: int, quantity: float, tier: int, ore_units: float|int, price_per_unit: float|int, value_contribution: float|int}> Ores, best first. `quantity` is the share of the moon (a fraction), `tier` the rarity 1 (R4) to 5 (R64). */
            'ores' => $m['ores'] ?? [],
            'occupied' => (bool) ($m['occupied'] ?? false),
            /** @var int|null */
            'occupying_corporation_id' => $m['occupying_corporation_id'] ?? null,
            'occupying_corporation_name' => $m['occupying_corporation_name'] ?? null,
        ];
    }
}
