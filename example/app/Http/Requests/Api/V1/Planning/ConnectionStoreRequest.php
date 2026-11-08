<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

class ConnectionStoreRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signature_id' => 'required|integer|exists:wholemap_signatures,id',
            'from_system_id' => 'required|integer',
            'from_system_name' => 'required|string|max:255',
            'to_system_id' => 'nullable|integer',
            'to_system_name' => 'nullable|string|max:255',
            'wormhole_type' => 'nullable|string|max:10',
            'size' => 'sometimes|string|in:small,medium,large,xlarge',
            'life' => 'sometimes|string|in:stable,critical',
            'mass' => 'sometimes|string|in:stable,destab,critical',
        ];
    }
}
