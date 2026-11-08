<?php

namespace App\Http\Resources\V1\Planning;

use App\Models\WormholeConnection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mapped wormhole connection between two systems.
 *
 * @mixin WormholeConnection
 */
class ConnectionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => $this->id,
            /** @var int|null */
            'signature_id' => $this->signature_id,
            /** @var int */
            'from_system_id' => $this->from_system_id,
            /** @var string|null */
            'from_system_name' => $this->from_system_name,
            /** @var int|null */
            'to_system_id' => $this->to_system_id,
            /** @var string|null */
            'to_system_name' => $this->to_system_name,
            /** @var string|null */
            'wormhole_type' => $this->wormhole_type,
            /** @var string|null small, medium, large or xlarge. */
            'size' => $this->size,
            /** @var string|null stable or critical. */
            'life' => $this->life,
            /** @var string|null stable, destab or critical. */
            'mass' => $this->mass,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
