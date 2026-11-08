<?php

namespace App\Http\Resources\V1\Planning;

use App\Models\WormholeJumpLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One logged jump through a wormhole connection.
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
        return [
            /** @var int */
            'id' => $this->id,
            /** @var int */
            'connection_id' => $this->connection_id,
            /** @var int|null */
            'character_id' => $this->character_id,
            /** @var string|null */
            'character_name' => $this->character_name,
            /** @var int|null */
            'ship_type_id' => $this->ship_type_id,
            /** @var string|null */
            'ship_type_name' => $this->ship_type_name,
            /** Ship mass in kg. */
            /** @var float|null */
            'ship_mass' => $this->ship_mass,
            'jumped_at' => $this->jumped_at?->toIso8601String(),
        ];
    }
}
