<?php

namespace App\Services\Reference;

use App\Services\MarketBrowserService;

/**
 * Market group tree and market type search for the reference API. Wraps
 * MarketBrowserService (owned by the market work) so the old
 * /api/market-browser routes and /api/v1/market-groups, /api/v1/types?q=
 * share one code path.
 */
class MarketGroupService
{
    public const MIN_QUERY_LENGTH = 2;

    public const SEARCH_LIMIT = 50;

    public function __construct(private MarketBrowserService $browser) {}

    /**
     * Top-level market groups by name, each with its child count.
     *
     * @return array<int, array{market_group_id: int, name: string, description: string|null, has_types: bool, child_count: int}>
     */
    public function rootGroups(): array
    {
        return $this->browser->getRootGroups();
    }

    /**
     * Every market group that leads to a manufacturable item, nested.
     *
     * @return array<int, array{market_group_id: int, name: string, has_types: bool, children: array}>
     */
    public function tree(): array
    {
        return $this->browser->getFullTree();
    }

    /**
     * A group with its breadcrumbs, child groups and published items, or
     * null when the group does not exist.
     *
     * @param  array<int>  $excludeMetaGroupIds
     * @return array{group: array, breadcrumbs: array, children: array, items: array}|null
     */
    public function contents(int $marketGroupId, array $excludeMetaGroupIds = []): ?array
    {
        $contents = $this->browser->getGroupContents($marketGroupId, $excludeMetaGroupIds);

        return $contents['group'] === null ? null : $contents;
    }

    /**
     * Published types with a market group whose name contains $query; empty
     * under 2 characters. LIKE wildcards in $query are not escaped (old
     * behaviour, kept).
     *
     * @param  array<int>  $excludeMetaGroupIds
     * @return array<int, array{type_id: int, name: string, icon_url: string, market_group_id: int|null}>
     */
    public function searchTypes(string $query, array $excludeMetaGroupIds = [], int $limit = self::SEARCH_LIMIT): array
    {
        return $this->browser->searchItems($query, $limit, $excludeMetaGroupIds);
    }
}
