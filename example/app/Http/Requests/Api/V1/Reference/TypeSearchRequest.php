<?php

namespace App\Http\Requests\Api\V1\Reference;

class TypeSearchRequest extends SearchRequest
{
    use FiltersMetaGroups;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(parent::rules(), $this->metaGroupRules());
    }
}
