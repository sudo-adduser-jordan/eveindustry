<?php

namespace App\Http\Resources\V1\Calculators;

use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A character's skill levels for one calculator. The resource is
 * ['character' => Character, 'skills' => array]; only the id and name of
 * the character are exposed.
 */
class CharacterSkillsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Character $character */
        $character = $this->resource['character'];

        return [
            'character_id' => (int) $character->character_id,
            'character_name' => (string) $character->name,
            'skills' => $this->resource['skills'],
        ];
    }
}
