<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\EveType;
use App\Models\MarketGroup;
use Illuminate\Database\Eloquent\Collection;

class MarketBrowserService
{
    /**
     * Get all top-level market groups (those with no parent).
     *
     * @return array<int, array{market_group_id: int, name: string, has_types: bool, child_count: int}>
     */
    public function getRootGroups(): array
    {
        $groups = MarketGroup::whereNull('parent_group_id')
            ->orderBy('name')
            ->get();

        return $groups->map(fn (MarketGroup $g) => [
            'market_group_id' => $g->market_group_id,
            'name' => $g->name,
            'description' => $g->description,
            'has_types' => $g->has_types,
            'child_count' => MarketGroup::where('parent_group_id', $g->market_group_id)->count(),
        ])->toArray();
    }

    /**
     * Get child groups and items for a specific market group.
     *
     * @param  array<int>  $excludeMetaGroupIds  Optional meta group IDs to exclude (e.g. [1] for T1, [4] for Faction)
     * @return array{group: array, children: array, items: array}
     */
    public function getGroupContents(int $marketGroupId, array $excludeMetaGroupIds = []): array
    {
        $group = MarketGroup::find($marketGroupId);

        if (! $group) {
            return ['group' => null, 'children' => [], 'items' => []];
        }

        $children = MarketGroup::where('parent_group_id', $marketGroupId)
            ->orderBy('name')
            ->get()
            ->map(fn (MarketGroup $g) => [
                'market_group_id' => $g->market_group_id,
                'name' => $g->name,
                'description' => $g->description,
                'has_types' => $g->has_types,
                'child_count' => MarketGroup::where('parent_group_id', $g->market_group_id)->count(),
            ])
            ->toArray();

        $items = [];
        if ($group->has_types) {
            $query = EveType::where('market_group_id', $marketGroupId)
                ->where('published', true);

            if (! empty($excludeMetaGroupIds)) {
                $query->where(function ($q) use ($excludeMetaGroupIds) {
                    $q->whereNull('meta_group_id')
                        ->orWhereNotIn('meta_group_id', $excludeMetaGroupIds);
                });
            }

            $items = $query->orderBy('name')
                ->get()
                ->map(fn (EveType $t) => [
                    'type_id' => $t->type_id,
                    'name' => $t->name,
                    'icon_url' => EsiService::getTypeImageUrl($t->type_id, 'icon', 32),
                ])
                ->toArray();
        }

        // Build breadcrumb path
        $breadcrumbs = $this->buildBreadcrumbs($group);

        return [
            'group' => [
                'market_group_id' => $group->market_group_id,
                'name' => $group->name,
                'description' => $group->description,
                'parent_group_id' => $group->parent_group_id,
            ],
            'breadcrumbs' => $breadcrumbs,
            'children' => $children,
            'items' => $items,
        ];
    }

    /**
     * Build breadcrumb navigation from a market group back to root.
     *
     * @return array<int, array{market_group_id: int, name: string}>
     */
    private function buildBreadcrumbs(MarketGroup $group): array
    {
        $crumbs = [];
        $current = $group;

        while ($current) {
            array_unshift($crumbs, [
                'market_group_id' => $current->market_group_id,
                'name' => $current->name,
            ]);
            $current = $current->parent_group_id
                ? MarketGroup::find($current->parent_group_id)
                : null;
        }

        return $crumbs;
    }

    /**
     * Get the full market group tree (all levels) without items.
     *
     * Only groups that contain manufacturable items (or are ancestors of such
     * groups) are included.  Items (EveTypes) are not included — they are
     * loaded lazily when a leaf group is clicked.
     *
     * @return array<int, array{market_group_id: int, name: string, has_types: bool, children: array}>
     */
    public function getFullTree(): array
    {
        $allGroups = MarketGroup::orderBy('name')->get();

        // Determine which market groups contain at least one manufacturable item
        $manufacturableGroupIds = $this->getManufacturableMarketGroupIds($allGroups);

        // Index groups by their parent_group_id for fast lookup
        $byParent = [];
        foreach ($allGroups as $group) {
            $parentId = $group->parent_group_id;
            $byParent[$parentId][] = $group;
        }

        return $this->buildTreeLevel($byParent, null, $manufacturableGroupIds);
    }

    /**
     * Collect the set of market_group_ids that contain manufacturable items
     * or are ancestors of such groups.
     *
     * @return array<int, true> Hash-set keyed by market_group_id
     */
    private function getManufacturableMarketGroupIds(Collection $allGroups): array
    {
        // 1. Get product type_ids from T1 manufacturing blueprints (exclude invented)
        $inventedBpIds = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_INVENTION)
            ->whereNotNull('products')
            ->get()
            ->flatMap(fn ($a) => collect($a->products)->pluck('type_id'))
            ->unique()
            ->toArray();

        $productTypeIds = BlueprintActivity::where('activity_id', BlueprintActivity::ACTIVITY_MANUFACTURING)
            ->whereNotNull('products')
            ->whereNotIn('blueprint_type_id', $inventedBpIds)
            ->get()
            ->flatMap(fn ($a) => collect($a->products)->pluck('type_id'))
            ->unique()
            ->toArray();

        // 2. Find the leaf market_group_ids that contain those product types
        $leafGroupIds = EveType::whereIn('type_id', $productTypeIds)
            ->where('published', true)
            ->whereNotNull('market_group_id')
            ->pluck('market_group_id')
            ->unique()
            ->toArray();

        // 3. Index groups by id so we can walk ancestors
        $groupsById = [];
        foreach ($allGroups as $group) {
            $groupsById[$group->market_group_id] = $group;
        }

        // 4. Walk up from every leaf to include all ancestors
        $keep = [];
        foreach ($leafGroupIds as $gid) {
            $current = $gid;
            while ($current !== null && ! isset($keep[$current])) {
                $keep[$current] = true;
                $current = isset($groupsById[$current])
                    ? $groupsById[$current]->parent_group_id
                    : null;
            }
        }

        // 5. Include all siblings of manufactured-item leaves.
        //    If a parent has at least one child with manufactured items,
        //    include ALL of that parent's children (one level higher check).
        $parentIdsOfLeaves = [];
        foreach ($leafGroupIds as $gid) {
            if (isset($groupsById[$gid]) && $groupsById[$gid]->parent_group_id !== null) {
                $parentIdsOfLeaves[$groupsById[$gid]->parent_group_id] = true;
            }
        }
        foreach ($allGroups as $group) {
            if ($group->parent_group_id !== null && isset($parentIdsOfLeaves[$group->parent_group_id])) {
                $keep[$group->market_group_id] = true;
            }
        }

        return $keep;
    }

    /**
     * Recursively build a tree level from the indexed groups, keeping only
     * groups present in the $keep set.
     */
    private function buildTreeLevel(array &$byParent, ?int $parentId, array &$keep): array
    {
        $groups = $byParent[$parentId] ?? [];
        $result = [];

        foreach ($groups as $group) {
            if (! isset($keep[$group->market_group_id])) {
                continue;
            }

            $children = $this->buildTreeLevel($byParent, $group->market_group_id, $keep);

            $result[] = [
                'market_group_id' => $group->market_group_id,
                'name' => $group->name,
                'has_types' => (bool) $group->has_types,
                'children' => $children,
            ];
        }

        return $result;
    }

    /**
     * Search for items by name across all market groups.
     *
     * @param  array<int>  $excludeMetaGroupIds  Optional meta group IDs to exclude (e.g. [1] for T1, [4] for Faction)
     * @return array<int, array{type_id: int, name: string, icon_url: string, market_group_id: int|null}>
     */
    public function searchItems(string $query, int $limit = 50, array $excludeMetaGroupIds = []): array
    {
        if (strlen($query) < 2) {
            return [];
        }

        $dbQuery = EveType::where('name', 'like', '%'.$query.'%')
            ->where('published', true)
            ->whereNotNull('market_group_id');

        if (! empty($excludeMetaGroupIds)) {
            $dbQuery->where(function ($q) use ($excludeMetaGroupIds) {
                $q->whereNull('meta_group_id')
                    ->orWhereNotIn('meta_group_id', $excludeMetaGroupIds);
            });
        }

        return $dbQuery->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (EveType $t) => [
                'type_id' => $t->type_id,
                'name' => $t->name,
                'icon_url' => EsiService::getTypeImageUrl($t->type_id, 'icon', 32),
                'market_group_id' => $t->market_group_id,
            ])
            ->toArray();
    }
}
