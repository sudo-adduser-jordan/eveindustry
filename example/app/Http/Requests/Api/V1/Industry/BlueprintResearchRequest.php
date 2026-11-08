<?php

namespace App\Http\Requests\Api\V1\Industry;

use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Validation\Rule;

class BlueprintResearchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Part of the blueprint name. */
            'search' => 'nullable|string|max:100',
            /** Only blueprints whose product is in this market group. */
            'market_group' => 'nullable|integer|min:1',
            /** `name` (default), `owned` (owned first when ascending) or `market_group`. */
            'sort' => ['nullable', Rule::in(['name', 'owned', 'market_group'])],
            /** `asc` (default) or `desc`. */
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ], $this->paginationRules());
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return $search === null || $search === '' ? null : (string) $search;
    }

    public function marketGroupId(): ?int
    {
        $id = $this->validated('market_group');

        return $id === null ? null : (int) $id;
    }

    public function sortBy(): string
    {
        return (string) ($this->validated('sort') ?? 'name');
    }

    public function sortDir(): string
    {
        return (string) ($this->validated('dir') ?? 'asc');
    }
}
