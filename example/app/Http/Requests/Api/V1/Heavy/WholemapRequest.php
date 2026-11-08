<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Optional system filter for the wormhole map.
 */
class WholemapRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Only the signatures and notes of this solar system. */
            'system_id' => 'nullable|integer',
        ];
    }
}
