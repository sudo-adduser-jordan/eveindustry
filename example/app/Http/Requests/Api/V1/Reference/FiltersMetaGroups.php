<?php

namespace App\Http\Requests\Api\V1\Reference;

/**
 * Optional exclude_meta_groups[] filter (EVE meta group ids, e.g. 1 = Tech I,
 * 2 = Tech II, 4 = Faction) for market type lists.
 */
trait FiltersMetaGroups
{
    /**
     * @return array<string, mixed>
     */
    protected function metaGroupRules(): array
    {
        return [
            /** Meta group ids to leave out, e.g. exclude_meta_groups[]=1&exclude_meta_groups[]=4. */
            'exclude_meta_groups' => 'nullable|array',
            'exclude_meta_groups.*' => 'integer|min:0',
        ];
    }

    /**
     * @return array<int>
     */
    public function excludeMetaGroups(): array
    {
        return array_map('intval', $this->validated('exclude_meta_groups') ?? []);
    }
}
