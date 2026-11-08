<?php

namespace App\Services\Reference;

use App\Models\BlueprintActivity;
use App\Services\EsiService;
use App\Services\ManufacturingCalculatorService;
use App\Services\ReactionsCalculatorService;

/**
 * Read-only lookups for manufacturing blueprints and reaction formulas:
 * name search and the SDE activity (materials, products, time).
 *
 * Shared by the old /api/calculators/{manufacturing,reactions,reaction-planner}
 * routes and /api/v1/blueprints, /api/v1/reactions/formulas.
 */
class BlueprintLookupService
{
    public const MIN_QUERY_LENGTH = 2;

    public function __construct(
        private EsiService $esi,
        private ManufacturingCalculatorService $manufacturing,
        private ReactionsCalculatorService $reactions,
    ) {}

    /**
     * Published blueprints whose name contains $query (local SDE first,
     * ESI search as a fallback). Empty for queries under 2 characters.
     *
     * @return list<array{type_id: int, name: string, group_id: int|null, icon_url: string}>
     */
    public function searchBlueprints(string $query, ?int $limit = null): array
    {
        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        return $this->limit($this->manufacturing->searchBlueprints($query), $limit);
    }

    /**
     * Published reaction formulas whose name contains $query.
     *
     * @return list<array{type_id: int, name: string, group_id: int|null, icon_url: string}>
     */
    public function searchReactionFormulas(string $query, ?int $limit = null): array
    {
        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        return $this->limit($this->reactions->searchReactions($query), $limit);
    }

    /**
     * @return array{materials: array, products: array, time: int}|null
     */
    public function manufacturingActivity(int $blueprintTypeId): ?array
    {
        return $this->esi->resolveBlueprintActivity($blueprintTypeId, BlueprintActivity::ACTIVITY_MANUFACTURING);
    }

    /**
     * @return array{materials: array, products: array, time: int}|null
     */
    public function reactionActivity(int $formulaTypeId): ?array
    {
        return $this->esi->resolveBlueprintActivity($formulaTypeId, BlueprintActivity::ACTIVITY_REACTION);
    }

    private function limit(array $results, ?int $limit): array
    {
        return $limit === null ? $results : array_slice($results, 0, $limit);
    }
}
