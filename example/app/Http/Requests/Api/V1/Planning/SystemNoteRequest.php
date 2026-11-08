<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

class SystemNoteRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'system_id' => 'required|integer',
            'system_name' => 'required|string|max:255',
            'status' => 'required|string|in:unknown,friendly,occupied,hostile',
            'comment' => 'nullable|string|max:255',
        ];
    }
}
