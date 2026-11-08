<?php

namespace App\Http\Requests\Api\V1\Reference;

use App\Http\Requests\Api\V1\ApiRequest;

class SystemStationsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge($this->paginationRules(), [
            /** Narrow to station names containing this text (ignored under 2 characters). */
            'q' => 'nullable|string|max:'.SearchRequest::MAX_QUERY_LENGTH,
        ]);
    }

    public function term(): string
    {
        return (string) ($this->validated('q') ?? '');
    }
}
