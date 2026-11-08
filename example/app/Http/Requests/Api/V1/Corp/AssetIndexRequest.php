<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Filters for the personal and corporation asset lists.
 */
class AssetIndexRequest extends ApiRequest
{
    public const SORT_FIELDS = ['type_name', 'quantity', 'location_name', 'character_name', 'owner_type'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            /** Case-insensitive substring over type, custom name, location, character and owner type. */
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|in:'.implode(',', self::SORT_FIELDS),
            'dir' => 'nullable|in:asc,desc',
            'include_children' => 'sometimes|boolean',
            'parent_item_id' => 'nullable|integer|min:1',
            'location_id' => 'nullable|integer|min:1',
            'snapshot_version' => ['nullable', 'string', 'max:20000', 'regex:/^\d+(,\d+)*$/'],
        ], $this->paginationRules());
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('include_children')) {
            $value = filter_var($this->input('include_children'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $this->merge(['include_children' => $value ?? $this->input('include_children')]);
        }
    }

    public function includeChildren(): bool
    {
        return $this->boolean('include_children', true);
    }

    public function parentItemId(): ?int
    {
        return $this->validated('parent_item_id') === null ? null : (int) $this->validated('parent_item_id');
    }

    public function locationId(): ?int
    {
        return $this->validated('location_id') === null ? null : (int) $this->validated('location_id');
    }

    public function snapshotVersion(): ?string
    {
        return $this->validated('snapshot_version');
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return $search === null || $search === '' ? null : (string) $search;
    }

    public function sort(): string
    {
        return (string) ($this->validated('sort') ?? 'type_name');
    }

    public function dir(): string
    {
        return (string) ($this->validated('dir') ?? 'asc');
    }
}
