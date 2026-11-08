<?php

namespace App\Http\Requests\Api\V1\Planning;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Page and per_page for the list endpoints (default 50, max 200).
 */
class PageRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->paginationRules();
    }
}
