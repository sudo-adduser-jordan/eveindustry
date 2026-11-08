<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Which character to locate.
 */
class LocationRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** One of the user's own characters. Defaults to the main character. */
            'character_id' => 'nullable|integer',
        ];
    }
}
