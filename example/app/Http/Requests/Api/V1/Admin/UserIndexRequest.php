<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\ApiRequest;

class UserIndexRequest extends ApiRequest
{
    public const SORTABLE = ['name', 'email', 'is_admin', 'is_active', 'last_login_at', 'created_at'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Matches the account name, email or any character name. */
            'search' => 'nullable|string|max:100',
            /** Default `created_at`. */
            'sort' => 'nullable|string|in:'.implode(',', self::SORTABLE),
            /** Default `desc`. */
            'direction' => 'nullable|string|in:asc,desc',
        ], $this->paginationRules());
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 20);
    }
}
