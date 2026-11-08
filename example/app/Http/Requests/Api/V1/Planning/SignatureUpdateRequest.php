<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Partial update: only the fields sent are changed. The system cannot be moved.
 */
class SignatureUpdateRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signature_id' => 'nullable|string|max:7',
            'type' => 'sometimes|string|in:unknown,combat,data,relic,ore,gas,wormhole,ghost',
            'name' => 'nullable|string|max:100',
            'bookmark' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
        ];
    }
}
