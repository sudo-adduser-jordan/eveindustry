<?php

namespace App\Http\Requests\Api\V1\Industry;

use App\Http\Requests\Api\V1\ApiRequest;
use Illuminate\Validation\Rule;

class ReactionFormulaResearchRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Part of the formula name. */
            'search' => 'nullable|string|max:100',
            /** Reaction group id: 1888 composite, 1889 biochemical, 4097 hybrid. */
            'category' => 'nullable|integer|min:1',
            /** `name` (default), `owned` (owned first when ascending) or `category`. */
            'sort' => ['nullable', Rule::in(['name', 'owned', 'category'])],
            /** `asc` (default) or `desc`. */
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return $search === null || $search === '' ? null : (string) $search;
    }

    public function groupId(): ?int
    {
        $id = $this->validated('category');

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
