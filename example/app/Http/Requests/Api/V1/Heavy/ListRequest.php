<?php

namespace App\Http\Requests\Api\V1\Heavy;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Page and per_page for the heavy-page lists.
 */
class ListRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->paginationRules();
    }
}
