<?php

namespace App\Http\Resources\V1\Planning;

use App\Models\WholemapSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A wormhole mapper signature, with its wormhole connection when there is one.
 *
 * @mixin WholemapSignature
 */
class SignatureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var int */
            'id' => $this->id,
            /** @var string|null The in-game code, e.g. ABC-123. */
            'signature_id' => $this->signature_id,
            /** @var int */
            'system_id' => $this->system_id,
            /** @var string|null */
            'system_name' => $this->system_name,
            /** @var string One of unknown, combat, data, relic, ore, gas, wormhole, ghost. */
            'type' => $this->type,
            /** @var string|null */
            'name' => $this->name,
            /** @var string|null */
            'bookmark' => $this->bookmark,
            /** @var string|null */
            'description' => $this->description,
            /** A locked signature cannot be edited (it can still be deleted). */
            'locked' => (bool) $this->locked,
            /** @var string|null */
            'created_by' => $this->created_by,
            /** @var string|null */
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            /** @var ConnectionResource|null */
            'connection' => $this->relationLoaded('wormholeConnection') && $this->wormholeConnection
                ? ConnectionResource::make($this->wormholeConnection)->resolve()
                : null,
        ];
    }
}
