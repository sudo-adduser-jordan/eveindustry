<?php

namespace App\Services\Reference;

use App\Services\InventionPlannerService;

/**
 * T2 items that can be invented: name search and the full invention chain.
 * Shared by /api/calculators/invention-planner/{search,item-data} and
 * /api/v1/invention/items.
 */
class InventionItemService
{
    public const MIN_QUERY_LENGTH = 2;

    public function __construct(private InventionPlannerService $planner) {}

    /**
     * Up to 20 inventable T2 items whose name contains $query.
     *
     * @return list<array{type_id: int, name: string, group_id: int|null, icon_url: string}>
     */
    public function search(string $query): array
    {
        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        return $this->planner->searchT2Items($query);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function chain(int $t2ItemTypeId): ?array
    {
        return $this->planner->resolveInventionChain($t2ItemTypeId);
    }
}
