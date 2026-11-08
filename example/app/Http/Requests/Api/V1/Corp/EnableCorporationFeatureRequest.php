<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Enable a corporation feature (donation tracking, build programs) with one
 * of the user's characters.
 */
class EnableCorporationFeatureRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** The character whose token provides the access. Defaults to the first eligible one. */
            'character_id' => 'nullable|integer',
        ];
    }

    public function characterId(): ?int
    {
        $id = $this->validated('character_id');

        return $id === null ? null : (int) $id;
    }
}
