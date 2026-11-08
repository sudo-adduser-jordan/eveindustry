<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Partial update: only the fields sent are changed. The origin cannot be moved.
 */
class ConnectionUpdateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_system_id' => 'nullable|integer',
            'to_system_name' => 'nullable|string|max:255',
            'wormhole_type' => 'nullable|string|max:10',
            'size' => 'sometimes|string|in:small,medium,large,xlarge',
            'life' => 'sometimes|string|in:stable,critical',
            'mass' => 'sometimes|string|in:stable,destab,critical',
        ];
    }
}
