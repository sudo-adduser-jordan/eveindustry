<?php

namespace App\Http\Resources\V1\Heavy;

use App\Models\WormholeJumpLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One recorded jump through a wormhole connection.
 *
 * @mixin WormholeJumpLog
 */
class JumpLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var WormholeJumpLog $j */
        $j = $this->resource;

        return [
            'id' => (int) $j->id,
            /** @var int|null */
            'character_id' => $j->character_id,
            /** @var string|null */
            'character_name' => $j->character_name,
            /** @var int|null */
            'ship_type_id' => $j->ship_type_id,
            /** @var string|null */
            'ship_type_name' => $j->ship_type_name,
            /** @var float|int|null Kilograms. */
            'ship_mass' => $j->ship_mass,
            /** ISO 8601. */
            'jumped_at' => $j->jumped_at?->toIso8601String(),
        ];
    }
}
