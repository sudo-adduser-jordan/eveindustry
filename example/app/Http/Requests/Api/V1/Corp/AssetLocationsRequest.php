<?php

namespace App\Http\Requests\Api\V1\Corp;

use App\Http\Requests\Api\V1\ApiRequest;

/**
 * Filter for the asset lists grouped by solar system and station.
 */
class AssetLocationsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** Case-insensitive substring over type, custom name, location, character and owner type. */
            'search' => 'nullable|string|max:100',
            'include_items' => 'sometimes|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('include_items')) {
            $value = filter_var($this->input('include_items'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $this->merge(['include_items' => $value ?? $this->input('include_items')]);
        }
    }

    public function includeItems(): bool
    {
        return $this->boolean('include_items', true);
    }

    public function search(): ?string
    {
        $search = $this->validated('search');

        return $search === null || $search === '' ? null : (string) $search;
    }
}
