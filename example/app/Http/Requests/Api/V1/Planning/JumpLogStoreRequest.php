<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

class JumpLogStoreRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'connection_id' => 'required|integer|exists:wormhole_connections,id',
            'character_id' => 'nullable|integer',
            'character_name' => 'nullable|string|max:100',
            'ship_type_id' => 'nullable|integer',
            'ship_type_name' => 'nullable|string|max:100',
            'ship_mass' => 'nullable|numeric',
        ];
    }
}
