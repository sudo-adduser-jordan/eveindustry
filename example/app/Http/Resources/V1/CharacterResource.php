<?php

namespace App\Http\Resources\V1;

use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A linked EVE character. Never includes ESI tokens.
 *
 * @mixin Character
 */
class CharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'character_id' => (int) $this->character_id,
            'name' => (string) $this->name,
            'corporation_id' => $this->corporation_id === null ? null : (int) $this->corporation_id,
            /** @var string|null */
            'corporation_name' => $this->corporation_name,
            'alliance_id' => $this->alliance_id === null ? null : (int) $this->alliance_id,
            /** @var string|null */
            'alliance_name' => $this->alliance_name,
            'is_main' => (bool) $this->is_main,
            'is_esi_active' => (bool) $this->is_esi_active,
            /** @var string|null */
            'cached_location' => $this->cached_location,
            /** @var string|null */
            'cached_training' => $this->cached_training,
            'portrait_url' => (string) $this->portrait_url,
            /** @var list<string> ESI scopes granted to this character. */
            'scopes' => array_values($this->scopes ?? []),
        ];
    }
}
