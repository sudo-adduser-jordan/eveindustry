<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * An endpoint that takes no input.
 */
class NoInputRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
