<?php

namespace App\Http\Resources\V1\Industry;

use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A linked character as the dashboard lists it. Never includes ESI tokens.
 *
 * @mixin Character
 */
class DashboardCharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'character_id' => (int) $this->character_id,
            'name' => (string) $this->name,
            'portrait_url' => (string) $this->portrait_url,
            'corporation_name' => $this->corporation_name,
            'corporation_logo' => $this->corporation_logo,
            'alliance_name' => $this->alliance_name,
            'alliance_logo' => $this->alliance_logo,
            /** Last known solar system, refreshed every 30 minutes. */
            'location' => $this->cached_location,
            /** Skill in training, refreshed every 30 minutes. */
            'training' => $this->cached_training,
            'is_main' => (bool) $this->is_main,
            /** False once ESI refused the character's token; they must sign in again. */
            'is_esi_active' => (bool) $this->is_esi_active,
        ];
    }
}
