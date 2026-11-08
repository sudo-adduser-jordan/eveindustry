<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

class SignatureStoreRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signature_id' => 'nullable|string|max:7',
            'system_id' => 'required|integer',
            'system_name' => 'required|string|max:255',
            'type' => 'required|string|in:unknown,combat,data,relic,ore,gas,wormhole,ghost',
            'name' => 'nullable|string|max:100',
            'bookmark' => 'nullable|string|max:100',
            'description' => 'nullable|string|max:1000',
        ];
    }
}
