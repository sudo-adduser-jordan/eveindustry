<?php

namespace App\Http\Requests\Api\V1\Reference;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Name search on a reference list: q is required, 2 to 100 characters
 * (the old /api routes return nothing under 2 characters).
 */
class SearchRequest extends ApiRequest
{
    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 100;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Text to search for (case-insensitive substring match on the name). */
            'q' => 'required|string|min:'.self::MIN_QUERY_LENGTH.'|max:'.self::MAX_QUERY_LENGTH,
        ];
    }

    public function term(): string
    {
        return (string) $this->validated('q');
    }
}
