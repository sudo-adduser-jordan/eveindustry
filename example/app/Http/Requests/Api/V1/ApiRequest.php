<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base FormRequest for /api/v1 endpoints.
 *
 * Validation failures render as 422 with code "validation_failed" (see
 * bootstrap/app.php). Lists use page/per_page: default 50, max 200.
 */
abstract class ApiRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 50;

    public const MAX_PER_PAGE = 200;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Rules for page and per_page, to merge into rules() on list endpoints.
     *
     * @return array<string, string>
     */
    protected function paginationRules(): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.self::MAX_PER_PAGE,
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
