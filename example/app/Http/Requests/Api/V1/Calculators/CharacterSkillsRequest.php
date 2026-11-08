<?php

namespace App\Http\Requests\Api\V1\Calculators;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Which of the user's characters to read skills from. A missing
 * character_id is a 422 here (the old routes returned 400).
 */
class CharacterSkillsRequest extends ApiRequest
{
    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'character_id' => 'required|integer|min:1',
        ];
    }

    public function characterId(): int
    {
        return (int) $this->validated('character_id');
    }
}
