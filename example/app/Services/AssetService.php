<?php

namespace App\Services;

use App\Jobs\RefreshAssetData;
use App\Models\Character;
use App\Models\EveType;
use App\Models\InvGroup;
use App\Models\SdeSolarSystem;
use App\Models\SdeStation;
use App\Services\Concerns\UsesAssetSnapshots;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AssetService
{
    use UsesAssetSnapshots;

    /** Cache TTL for aggregated asset data: stored forever, refreshed by background job. */
    public const ASSET_CACHE_TTL = 0;

    /** TTL for negative structure resolution cache: 10 minutes in seconds. */
    public const STRUCTURE_FAIL_CACHE_TTL = 600;

    /** IDs above this threshold are player-owned structures. */
    public const PLAYER_STRUCTURE_ID_THRESHOLD = 1000000000000;

    /** NPC station location ID range (inclusive lower, exclusive upper). */
    public const NPC_STATION_ID_MIN = 60000000;

    public const NPC_STATION_ID_MAX = 64000000;

    /** Solar system location ID range (inclusive lower, exclusive upper). */
    public const SOLAR_SYSTEM_ID_MIN = 30000000;

    public const SOLAR_SYSTEM_ID_MAX = 33000000;

    /** Friendly names for corporation hangar location flags. */
    public const CORP_HANGAR_FLAGS = [
        'CorpSAG1' => 'Corp Hangar 1',
        'CorpSAG2' => 'Corp Hangar 2',
        'CorpSAG3' => 'Corp Hangar 3',
        'CorpSAG4' => 'Corp Hangar 4',
        'CorpSAG5' => 'Corp Hangar 5',
        'CorpSAG6' => 'Corp Hangar 6',
        'CorpSAG7' => 'Corp Hangar 7',
        'CorpDeliveries' => 'Corp Deliveries',
    ];

    /** EVE category ID for Ships. */
    public const SHIP_CATEGORY_ID = 6;

    /** EVE group ID for Fuel Blocks. */
    public const FUEL_BLOCK_GROUP_ID = 1136;

    /** EVE group IDs for material categories. */
    public const MATERIAL_GROUPS = [
        18 => 'Minerals',
        423 => 'Ice Products',
        427 => 'Moon Materials',
        428 => 'Intermediate Materials',
        429 => 'Composite Materials',
        712 => 'Molecular-Forged Materials',
        974 => 'Hybrid Polymers',
        1034 => 'Salvage Materials',
        1033 => 'Ancient Salvage',
        self::FUEL_BLOCK_GROUP_ID => 'Fuel Blocks',
        1857 => 'Biochemical Material',
        4051 => 'Abyssal Materials',
        4168 => 'Triglavian Materials',
    ];

    public function __construct(
        protected EsiService $esi,
        protected ?MarketService $market = null,
    ) {}

    /**
     * Return a user-facing warning string if the main character cannot provide
     * corporation assets, or null if everything looks fine.
     *
     * Checks performed (in order):
     *  1. A main character must exist.
     *  2. The main character must have the esi-assets.read_corporation_assets.v1 scope.
     *  3. If the main character also has the esi-characters.read_corporation_roles.v1
     *     scope, their in-game roles are verified; they must hold Director or
     *     CEO to access corporation assets (Station_Manager alone is not enough).
     */
    public function getCorporationAssetsWarning(int $userId): ?string
    {
        $mainCharacter = Character::where('user_id', $userId)
            ->where('is_main', true)
            ->first();

        if (! $mainCharacter) {
            return 'No main character is set. Corporation assets can only be retrieved by your main character. Please mark a character as your main.';
        }

        if (! $mainCharacter->hasScope('esi-assets.read_corporation_assets.v1')) {
            return "Your main character ({$mainCharacter->name}) does not have the Corporation Assets ESI scope. Re-authenticate to grant the required scope.";
        }

        if ($mainCharacter->hasScope('esi-characters.read_corporation_roles.v1')) {
            $roles = $this->esi->getCharacterRoles($mainCharacter);
            $characterRoles = $roles['roles'] ?? [];
            if (
                ! in_array('Director', $characterRoles) &&
                ! in_array('CEO', $characterRoles)
            ) {
                return "Your main character ({$mainCharacter->name}) does not hold the Director or CEO role in their corporation. Corporation assets cannot be retrieved.";
            }
        }

        return null;
    }

    /**
     * Whether corporation assets can be fetched for this main character: it
     * must be in a corporation, hold Director or CEO (is_director_ceo, set by
     * characters:refresh-data) and have the corporation assets scope. Both
     * the background fetch and the /api/v1 corporation assets gate use this.
     */
    public function canFetchCorporationAssets(?Character $mainCharacter): bool
    {
        return $this->corporationAssetsDenial($mainCharacter) === null;
    }

    /**
     * Why corporation assets cannot be fetched for this main character, or
     * null when they can. Same checks as canFetchCorporationAssets(), without
     * calling ESI; the /api/v1 403 carries this as its message.
     */
    public function corporationAssetsDenial(?Character $mainCharacter): ?string
    {
        if ($mainCharacter === null) {
            return 'No main character is set. Corporation assets can only be retrieved by your main character.';
        }

        if (! $mainCharacter->corporation_id) {
            return "Your main character ({$mainCharacter->name}) is not in a corporation.";
        }

        if (! $mainCharacter->is_director_ceo) {
            return "Your main character ({$mainCharacter->name}) does not hold the Director or CEO role in their corporation.";
        }

        if (! $mainCharacter->hasScope('esi-assets.read_corporation_assets.v1')) {
            return "Your main character ({$mainCharacter->name}) does not have the Corporation Assets ESI scope. Re-authenticate to grant it.";
        }

        if (! RefreshEligibility::corporation($mainCharacter, (int) $mainCharacter->corporation_id, 'esi-assets.read_corporation_assets.v1', ['Director'])) {
            return 'Corporation asset access needs to be verified again.';
        }

        return null;
    }

    public function mainCharacterFor(int $userId): ?Character
    {
        return Character::where('user_id', $userId)->where('is_main', true)->first();
    }

    /**
     * Get all assets across all characters and their corp hangars for a user.
     *
     * Each asset entry includes: type_id, type_name, quantity, location_name,
     * character_name, owner_type ('personal' or 'corporation'), location_flag.
     * Items inside containers/ships have a parent_item_id set.
     */
    public function getAssetsForUser(int $userId): array
    {
        if ($this->usesDurableSnapshots()) {
            return $this->readSnapshotAssets($userId);
        }

        $cacheKey = "assets.user.{$userId}";
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return $cached;
        }

        // An evicted 12 MB inventory must not turn a page load into an ESI scan.
        RefreshAssetData::dispatch($userId);

        return [];
    }

    /**
     * Fetch fresh asset data from ESI and store it in the cache forever.
     *
     * Called by the background RefreshAssetData job and on first access.
     */
    public function fetchAndCacheAssets(int $userId): array
    {
        if ($this->usesDurableSnapshots()) {
            return $this->refreshSnapshots($userId);
        }

        $cacheKey = "assets.user.{$userId}";
        $characters = Character::where('user_id', $userId)->where('is_esi_active', true)->get();
        $assets = [];
        $processedCorps = [];

        // Collect only characters that have the structure-read scope.
        // Trying characters without it produces 401 errors that burn the ESI
        // error budget; a 403 from a scoped character (no docking access) is
        // handled gracefully by the resolver's per-character fallback loop.
        $structureCharacters = $characters->filter(
            fn ($c) => $c->hasScope('esi-universe.read_structures.v1')
        )->values()->all();

        $mainCharacter = $characters->firstWhere('is_main', true);

        foreach ($characters as $character) {
            // Personal assets
            if ($character->hasScope('esi-assets.read_assets.v1')) {
                $charAssets = $this->esi->getCharacterAssets($character);
                $assets = array_merge($assets, $this->enrichAssets(
                    $charAssets,
                    $character,
                    'personal',
                    $structureCharacters
                ));
            }
        }

        // Corporation assets: only retrieved via the main character.
        // Using a non-main character would silently skip corp assets for accounts
        // where only the main has Director role; this also avoids redundant ESI
        // calls across alt characters of the same corp.
        // Skip entirely if the main character is not a director/CEO or lacks the
        // corporation assets scope — both are required for the ESI call to succeed.
        if ($this->canFetchCorporationAssets($mainCharacter)) {
            $corpId = $mainCharacter->corporation_id;
            if (! isset($processedCorps[$corpId])) {
                $corpAssets = $this->esi->getCorporationAssets($mainCharacter, $corpId);
                if (! empty($corpAssets)) {
                    $assets = array_merge($assets, $this->enrichAssets(
                        $corpAssets,
                        $mainCharacter,
                        'corporate',
                        $structureCharacters
                    ));
                    $processedCorps[$corpId] = true;
                }
            }
        }

        // Core collection throws on failed/incomplete pagination. Reaching here
        // therefore includes the legitimate transition to an empty inventory.
        Cache::forever($cacheKey, $assets);
        Cache::forever("assets.user.{$userId}.cached_at", time());

        return $assets;
    }

    /**
     * Search and sort the user's assets, returning a hierarchical tree.
     *
     * Top-level items are containers/ships/standalone items. Items inside
     * containers or ships are nested as 'children'. When searching, a parent
     * is included if it or any of its children match the search term.
     *
     * @param  string|null  $ownerType  Filter by owner type ('personal', 'corporate', or null for all)
     */
    public function searchAssets(int $userId, ?string $search = null, string $sortBy = 'type_name', string $sortDir = 'asc', ?string $ownerType = null): array
    {
        $assets = $this->getAssetsForUser($userId);

        if ($ownerType !== null) {
            $assets = array_values(array_filter($assets, fn ($a) => ($a['owner_type'] ?? '') === $ownerType));
        }
        $tree = $this->buildAssetTree($assets);

        // Filter by search term (search both parents and children)
        if ($search) {
            $search = mb_strtolower($search);
            $filtered = [];
            foreach ($tree as $asset) {
                $parentMatches = $this->assetMatchesSearch($asset, $search);

                if ($parentMatches) {
                    // Parent matches: include with all children
                    $filtered[] = $asset;
                } else {
                    // Check if any children match
                    $matchingChildren = array_filter($asset['children'], function ($child) use ($search) {
                        return $this->assetMatchesSearch($child, $search);
                    });

                    if (! empty($matchingChildren)) {
                        // Include parent with only matching children
                        $asset['children'] = array_values($matchingChildren);
                        $filtered[] = $asset;
                    }
                }
            }
            $tree = $filtered;
        }

        // Sort top-level items
        $validSortFields = ['type_name', 'quantity', 'location_name', 'character_name', 'owner_type'];
        if (! in_array($sortBy, $validSortFields)) {
            $sortBy = 'type_name';
        }

        usort($tree, function ($a, $b) use ($sortBy, $sortDir) {
            $valA = $a[$sortBy] ?? '';
            $valB = $b[$sortBy] ?? '';

            if (is_numeric($valA) && is_numeric($valB)) {
                $cmp = $valA <=> $valB;
            } else {
                $cmp = strcasecmp((string) $valA, (string) $valB);
            }

            return $sortDir === 'desc' ? -$cmp : $cmp;
        });

        // Sort children within each parent
        foreach ($tree as &$item) {
            if (! empty($item['children'])) {
                usort($item['children'], function ($a, $b) use ($sortBy, $sortDir) {
                    $valA = $a[$sortBy] ?? '';
                    $valB = $b[$sortBy] ?? '';

                    if (is_numeric($valA) && is_numeric($valB)) {
                        $cmp = $valA <=> $valB;
                    } else {
                        $cmp = strcasecmp((string) $valA, (string) $valB);
                    }

                    return $sortDir === 'desc' ? -$cmp : $cmp;
                });
            }
        }
        unset($item);

        return array_values($tree);
    }

    /**
     * Build a hierarchical tree from flat enriched assets.
     *
     * Items whose location_type is 'item' are nested under their parent.
     * Top-level items get a 'children' array.
     */
    public function buildAssetTree(array $assets): array
    {
        // Index by item_id
        $byId = [];
        foreach ($assets as $asset) {
            $asset['children'] = [];
            $byId[$asset['item_id']] = $asset;
        }

        $tree = [];

        foreach ($byId as $itemId => $asset) {
            $parentId = $asset['parent_item_id'] ?? null;
            if ($parentId && isset($byId[$parentId])) {
                $byId[$parentId]['children'][] = &$byId[$itemId];
            } else {
                $tree[] = &$byId[$itemId];
            }
        }

        return $tree;
    }

    /**
     * Check if an asset matches the search term.
     */
    protected function assetMatchesSearch(array $asset, string $search): bool
    {
        return str_contains(mb_strtolower($asset['type_name']), $search)
            || str_contains(mb_strtolower($asset['name'] ?? ''), $search)
            || str_contains(mb_strtolower($asset['location_name']), $search)
            || str_contains(mb_strtolower($asset['character_name']), $search)
            || str_contains(mb_strtolower($asset['owner_type']), $search);
    }

    /**
     * Clear cached asset data for a user.
     */
    public function clearUserCache(int $userId): void
    {
        $this->clearEsiCaches($userId);

        Cache::forget("assets.user.{$userId}");
        Cache::forget("assets.user.{$userId}.cached_at");
    }

    /**
     * Clear the ESI caches and queue a RefreshAssetData forced past its
     * freshness check. Used by the Refresh buttons on the asset pages.
     */
    public function queueRefresh(int $userId): void
    {
        // Rebuild the view at the earliest allowed upstream refresh. A manual
        // action must not discard still-fresh source representations.
        RefreshAssetData::dispatch($userId, force: true);
    }

    /**
     * Clear the ESI-level caches behind a user's assets (character and
     * corporation assets, structure lookups) so the next refresh fetches
     * fresh data. The user's built asset list stays until a refresh replaces it.
     */
    public function clearEsiCaches(int $userId): void
    {
        // Clear negative structure resolution caches for any structures in the cached data
        $cachedAssets = Cache::get("assets.user.{$userId}", []);
        $clearedStructures = [];
        foreach ($cachedAssets as $asset) {
            $locId = $asset['location_id'] ?? 0;
            if ($locId > self::PLAYER_STRUCTURE_ID_THRESHOLD && ! isset($clearedStructures[$locId])) {
                Cache::forget("esi.structure.fail.{$locId}");
                Cache::forget("esi.structure.{$locId}");
                $clearedStructures[$locId] = true;
            }
        }

        $characters = Character::where('user_id', $userId)->get();
        $clearedCorps = [];

        foreach ($characters as $character) {
            Cache::forget("esi.assets.{$character->character_id}");

            $corpId = $character->corporation_id;
            if ($corpId && ! isset($clearedCorps[$corpId])) {
                Cache::forget("esi.corp_assets.{$corpId}");
                $clearedCorps[$corpId] = true;
            }
        }
    }

    /**
     * Get the ISO 8601 timestamp when the asset cache was last updated.
     *
     * Returns null if there is no cached data.
     */
    public function getLastUpdatedTimestamp(int $userId): ?string
    {
        if ($this->usesDurableSnapshots()) {
            return $this->snapshotMetadata($userId)['last_updated'];
        }

        $cacheKey = "assets.user.{$userId}.cached_at";
        $cachedAt = Cache::get($cacheKey);

        if (! $cachedAt) {
            return null;
        }

        return date('c', $cachedAt);
    }

    /**
     * Get materials across all characters, grouped by material category with ISK values.
     *
     * Returns ['groups' => [...], 'grand_total' => float]
     */
    public function getMaterialsForUser(int $userId): array
    {
        // Use the flat asset list directly — it already contains all items
        // including those nested in containers/ships
        $assets = $this->usesDurableSnapshots() ? $this->snapshotMaterialAssets($userId) : $this->getAssetsForUser($userId);

        $typeGroups = [];
        try {
            foreach (array_chunk(array_unique(array_column($assets, 'type_id')), 1000) as $types) {
                $typeGroups += EveType::whereIn('type_id', $types)->pluck('group_id', 'type_id')->all();
            }
        } catch (\Exception $e) {
            // Legacy installations can still use the existing type fallback.
        }

        // Aggregate by type within each material group
        $grouped = [];
        foreach ($assets as $asset) {
            $typeId = $asset['type_id'] ?? 0;
            if ($typeId <= 0) {
                continue;
            }

            // Look up group_id from SDE
            if (! array_key_exists($typeId, $typeGroups)) {
                $typeGroups[$typeId] = $this->resolveGroupId($typeId);
            }
            $groupId = $typeGroups[$typeId];
            if ($groupId === null || ! isset(self::MATERIAL_GROUPS[$groupId])) {
                continue;
            }

            $groupName = self::MATERIAL_GROUPS[$groupId];
            $key = "{$groupId}_{$typeId}";

            if (! isset($grouped[$groupName])) {
                $grouped[$groupName] = [];
            }

            if (! isset($grouped[$groupName][$key])) {
                $grouped[$groupName][$key] = [
                    'type_id' => $typeId,
                    'type_name' => $asset['type_name'],
                    'quantity' => 0,
                    'unit_price' => 0,
                    'total_value' => 0,
                ];
            }

            $grouped[$groupName][$key]['quantity'] += $asset['quantity'] ?? 0;
        }

        // Fetch prices and compute totals
        $grandTotal = 0;
        $result = [];

        // Batch-load all unit prices up front to avoid an N+1 query per item.
        $allTypeIds = [];
        foreach ($grouped as $items) {
            foreach ($items as $item) {
                $allTypeIds[] = $item['type_id'];
            }
        }
        $priceMap = $this->resolveUnitPricesForTypes($allTypeIds);

        foreach ($grouped as $groupName => $items) {
            $groupTotal = 0;
            $groupItems = [];

            foreach ($items as $item) {
                $unitPrice = $priceMap[$item['type_id']] ?? 0.0;
                $totalValue = $unitPrice * $item['quantity'];
                $groupTotal += $totalValue;

                $groupItems[] = [
                    'type_id' => $item['type_id'],
                    'type_name' => $item['type_name'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $unitPrice,
                    'total_value' => $totalValue,
                ];
            }

            // Sort items by type_name
            usort($groupItems, fn ($a, $b) => strcasecmp($a['type_name'], $b['type_name']));

            $grandTotal += $groupTotal;
            $result[] = [
                'name' => $groupName,
                'items' => $groupItems,
                'total' => $groupTotal,
            ];
        }

        // Sort groups by name
        usort($result, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return [
            'groups' => $result,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * Get all ships across all characters grouped by ship type (InvGroup).
     *
     * @return array{ship_types: list<array{group_name: string, count: int, ships: list<array<string,mixed>>}>, total_ships: int, type_count: int}
     */
    public function getShipsForUser(int $userId): array
    {
        $assets = $this->usesDurableSnapshots() ? $this->snapshotShipAssets($userId) : $this->getAssetsForUser($userId);

        // Build a map of group_id => group_name for the Ship category (category_id = 6)
        $shipGroupMap = [];
        try {
            $groups = InvGroup::where('category_id', self::SHIP_CATEGORY_ID)->get();
            foreach ($groups as $group) {
                $shipGroupMap[$group->group_id] = $group->name;
            }
        } catch (\Exception $e) {
            // Table may not exist yet
        }

        if (empty($shipGroupMap)) {
            return ['ship_types' => [], 'total_ships' => 0, 'type_count' => 0];
        }

        // Resolve group_id for every distinct type_id that appears in assets
        $typeIds = array_values(array_unique(array_column($assets, 'type_id')));
        $typeGroupMap = [];
        try {
            EveType::whereIn('type_id', $typeIds)
                ->get(['type_id', 'group_id'])
                ->each(function ($t) use (&$typeGroupMap) {
                    $typeGroupMap[$t->type_id] = $t->group_id;
                });
        } catch (\Exception $e) {
            // Table may not exist yet
        }

        // Filter assets to ships only and group by ship type
        $grouped = [];
        $totalShips = 0;
        foreach ($assets as $asset) {
            $typeId = $asset['type_id'] ?? 0;
            if ($typeId <= 0) {
                continue;
            }

            $groupId = $typeGroupMap[$typeId] ?? null;
            if ($groupId === null || ! isset($shipGroupMap[$groupId])) {
                continue;
            }

            $groupName = $shipGroupMap[$groupId];
            if (! isset($grouped[$groupName])) {
                $grouped[$groupName] = ['group_name' => $groupName, 'count' => 0, 'ships' => []];
            }

            $grouped[$groupName]['count']++;
            $totalShips++;
            $grouped[$groupName]['ships'][] = $asset;
        }

        // Sort groups alphabetically; sort ships within each group by type_name then custom name
        ksort($grouped);
        foreach ($grouped as &$group) {
            usort($group['ships'], function ($a, $b) {
                $cmp = strcasecmp($a['type_name'] ?? '', $b['type_name'] ?? '');
                if ($cmp !== 0) {
                    return $cmp;
                }

                return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
            });
        }

        return [
            'ship_types' => array_values($grouped),
            'total_ships' => $totalShips,
            'type_count' => count($grouped),
        ];
    }

    /**
     * Returns [
     *   'summary' => ['total_items' => int, 'total_quantity' => int, 'location_count' => int, 'type_count' => int],
     *   'locations' => [
     *     [
     *       'location_name' => string,
     *       'location_id' => int,
     *       'item_count' => int,
     *       'total_quantity' => int,
     *       'items' => [
     *         ['type_id' => int, 'type_name' => string, 'quantity' => int, 'category' => string],
     *       ],
     *     ],
     *   ],
     * ]
     */
    public function getLocationGroupedAssets(int $userId, ?string $search = null): array
    {
        $assets = $this->getAssetsForUser($userId);

        // Filter by search term if provided
        if ($search) {
            $searchLower = mb_strtolower($search);
            $assets = array_filter($assets, function ($asset) use ($searchLower) {
                return $this->assetMatchesSearch($asset, $searchLower);
            });
        }

        // Group by location, aggregate duplicate types within each location
        $locationMap = [];
        $uniqueTypes = [];

        foreach ($assets as $asset) {
            $locName = $asset['location_name'] ?? 'Unknown Location';
            $locId = $asset['location_id'] ?? 0;
            $typeId = $asset['type_id'] ?? 0;
            $typeName = $asset['type_name'] ?? 'Unknown';
            $quantity = $asset['quantity'] ?? 0;

            $locKey = $locId ?: $locName;

            if (! isset($locationMap[$locKey])) {
                $locationMap[$locKey] = [
                    'location_name' => $locName,
                    'location_id' => $locId,
                    'items' => [],
                    'total_quantity' => 0,
                ];
            }

            // Aggregate items by type_id within each location
            if (! isset($locationMap[$locKey]['items'][$typeId])) {
                $locationMap[$locKey]['items'][$typeId] = [
                    'type_id' => $typeId,
                    'type_name' => $typeName,
                    'quantity' => 0,
                ];
            }

            $locationMap[$locKey]['items'][$typeId]['quantity'] += $quantity;
            $locationMap[$locKey]['total_quantity'] += $quantity;
            $uniqueTypes[$typeId] = true;
        }

        // Build final locations array, sorting items within each location
        $locations = [];
        foreach ($locationMap as $loc) {
            $items = array_values($loc['items']);
            usort($items, fn ($a, $b) => strcasecmp($a['type_name'], $b['type_name']));

            $locations[] = [
                'location_name' => $loc['location_name'],
                'location_id' => $loc['location_id'],
                'item_count' => count($items),
                'total_quantity' => $loc['total_quantity'],
                'items' => $items,
            ];
        }

        // Sort locations by name
        usort($locations, fn ($a, $b) => strcasecmp($a['location_name'], $b['location_name']));

        // Build summary
        $totalItems = count($assets);
        $totalQuantity = 0;
        foreach ($assets as $asset) {
            $totalQuantity += $asset['quantity'] ?? 0;
        }

        return [
            'summary' => [
                'total_items' => $totalItems,
                'total_quantity' => $totalQuantity,
                'location_count' => count($locations),
                'type_count' => count($uniqueTypes),
            ],
            'locations' => $locations,
        ];
    }

    /**
     * Group assets by Solar System > Station > hierarchical item tree.
     *
     * Returns [
     *   'summary' => ['total_items' => int, 'total_quantity' => int, 'system_count' => int, 'location_count' => int, 'type_count' => int],
     *   'solar_systems' => [
     *     [
     *       'solar_system_id' => int|null,
     *       'solar_system_name' => string,
     *       'stations' => [
     *         [
     *           'location_id' => int,
     *           'location_name' => string,
     *           'items' => [ <tree with 'children'> ],
     *         ],
     *       ],
     *     ],
     *   ],
     * ]
     *
     * @param  string|null  $ownerType  Filter by owner type ('personal', 'corporate', or null for all)
     */
    public function getSolarSystemGroupedAssets(int $userId, ?string $search = null, ?string $ownerType = null): array
    {
        $assets = $this->getAssetsForUser($userId);

        if ($ownerType !== null) {
            $assets = array_values(array_filter($assets, fn ($a) => ($a['owner_type'] ?? '') === $ownerType));
        }

        // Build hierarchical tree so items inside containers/ships appear as children
        $tree = $this->buildAssetTree($assets);

        // Filter tree by search term when provided
        if ($search) {
            $searchLower = mb_strtolower($search);
            $filtered = [];
            foreach ($tree as $asset) {
                if ($this->assetMatchesSearch($asset, $searchLower)) {
                    $filtered[] = $asset;
                } elseif (! empty($asset['children'])) {
                    $matchingChildren = array_filter($asset['children'], fn ($c) => $this->assetMatchesSearch($c, $searchLower));
                    if (! empty($matchingChildren)) {
                        $asset['children'] = array_values($matchingChildren);
                        $filtered[] = $asset;
                    }
                }
            }
            $tree = $filtered;
        }

        // Collect unique NPC station IDs so we can batch-resolve their solar systems
        $npcStationIds = [];
        foreach ($tree as $asset) {
            $locId = $asset['location_id'] ?? 0;
            if ($locId >= self::NPC_STATION_ID_MIN && $locId < self::NPC_STATION_ID_MAX) {
                $npcStationIds[$locId] = true;
            }
        }

        // Batch-resolve NPC station → solar system name
        $npcSystemsByStation = [];
        if (! empty($npcStationIds)) {
            $stations = SdeStation::whereIn('station_id', array_keys($npcStationIds))
                ->get(['station_id', 'solar_system_id']);

            $systemIds = $stations->pluck('solar_system_id')->filter()->unique()->values()->all();
            $systemNames = [];
            if (! empty($systemIds)) {
                $systemNames = SdeSolarSystem::whereIn('solar_system_id', $systemIds)
                    ->pluck('solar_system_name', 'solar_system_id')
                    ->all();
            }

            foreach ($stations as $stn) {
                $sysId = $stn->solar_system_id;
                $npcSystemsByStation[$stn->station_id] = [
                    'id' => $sysId,
                    'name' => $systemNames[$sysId] ?? "System #{$sysId}",
                ];
            }
        }

        // Group top-level items by station, resolve solar system for each
        $stationMap = [];
        foreach ($tree as $asset) {
            $locId = $asset['location_id'] ?? 0;
            $locName = $asset['location_name'] ?? 'Unknown Location';
            $locKey = $locId ?: $locName;

            if (! isset($stationMap[$locKey])) {
                $solarSystem = $this->resolveStationSolarSystem($locId, $npcSystemsByStation);
                $stationMap[$locKey] = [
                    'location_id' => $locId,
                    'location_name' => $locName,
                    'solar_system_id' => $solarSystem['id'],
                    'solar_system_name' => $solarSystem['name'],
                    'items' => [],
                ];
            }
            $stationMap[$locKey]['items'][] = $asset;
        }

        // Group stations by solar system
        $solarSystemMap = [];
        foreach ($stationMap as $station) {
            $sysKey = $station['solar_system_id'] ?: $station['solar_system_name'];
            if (! isset($solarSystemMap[$sysKey])) {
                $solarSystemMap[$sysKey] = [
                    'solar_system_id' => $station['solar_system_id'],
                    'solar_system_name' => $station['solar_system_name'],
                    'stations' => [],
                ];
            }
            $solarSystemMap[$sysKey]['stations'][] = [
                'location_id' => $station['location_id'],
                'location_name' => $station['location_name'],
                'items' => $station['items'],
            ];
        }

        // Sort solar systems alphabetically, stations within each system alphabetically
        $solarSystems = array_values($solarSystemMap);
        usort($solarSystems, fn ($a, $b) => strcasecmp($a['solar_system_name'], $b['solar_system_name']));
        foreach ($solarSystems as &$sys) {
            usort($sys['stations'], fn ($a, $b) => strcasecmp($a['location_name'], $b['location_name']));
        }
        unset($sys);

        // Build summary stats
        $totalQuantity = 0;
        $uniqueTypes = [];
        foreach ($assets as $asset) {
            $totalQuantity += $asset['quantity'] ?? 0;
            $uniqueTypes[$asset['type_id'] ?? 0] = true;
        }

        return [
            'summary' => [
                'total_items' => count($assets),
                'total_quantity' => $totalQuantity,
                'system_count' => count($solarSystems),
                'location_count' => count($stationMap),
                'type_count' => count($uniqueTypes),
            ],
            'solar_systems' => $solarSystems,
        ];
    }

    /**
     * Resolve the solar system id/name for a given root station/structure location ID.
     *
     * @param  array  $npcSystemsByStation  Pre-loaded map of NPC station_id => ['id', 'name']
     */
    protected function resolveStationSolarSystem(int $locationId, array $npcSystemsByStation): array
    {
        // NPC station already resolved in bulk
        if (isset($npcSystemsByStation[$locationId])) {
            return $npcSystemsByStation[$locationId];
        }

        // Direct solar system location
        if ($locationId >= self::SOLAR_SYSTEM_ID_MIN && $locationId < self::SOLAR_SYSTEM_ID_MAX) {
            $system = $this->esi->getSystem($locationId);
            if ($system && isset($system['name'])) {
                return ['id' => $locationId, 'name' => $system['name']];
            }
        }

        // Player-owned structure — check the ESI structure cache
        if ($locationId > self::PLAYER_STRUCTURE_ID_THRESHOLD) {
            $structureCache = Cache::get("esi.structure.{$locationId}");
            if ($structureCache && isset($structureCache['solar_system_id'])) {
                $sysId = (int) $structureCache['solar_system_id'];
                $system = $this->esi->getSystem($sysId);
                if ($system && isset($system['name'])) {
                    return ['id' => $sysId, 'name' => $system['name']];
                }
            }
        }

        return ['id' => null, 'name' => 'Unknown System'];
    }

    /**
     * Resolve the group_id for a type_id from SDE.
     */
    protected function resolveGroupId(int $typeId): ?int
    {
        try {
            $type = EveType::find($typeId);
            if ($type) {
                return $type->group_id;
            }
        } catch (\Exception $e) {
            // Table may not exist
        }

        return null;
    }

    /**
     * Batch-resolve Jita unit prices for many types through the canonical
     * price source, MarketService::getSellPrices(): one sell-order query and
     * one market_prices query, not one per type.
     *
     * @param  array<int>  $typeIds
     * @return array<int, float> type_id => unit price (0 when no source has one)
     */
    protected function resolveUnitPricesForTypes(array $typeIds): array
    {
        $typeIds = array_values(array_unique(array_filter($typeIds)));
        if (empty($typeIds)) {
            return [];
        }

        try {
            return ($this->market ?? app(MarketService::class))
                ->getSellPrices($typeIds, MarketService::DEFAULT_REGION_ID);
        } catch (\Exception $e) {
            // Market tables may not exist yet; value everything at 0.
            return [];
        }
    }

    /**
     * Enrich raw ESI assets with type names, location names, character info, and custom names.
     */
    protected function enrichAssets(array $rawAssets, Character $character, string $ownerType, array $structureCharacters = []): array
    {
        $enriched = [];
        // Build a map of item_id -> asset for resolving nested locations (items inside containers/ships)
        $assetMap = [];
        foreach ($rawAssets as $asset) {
            $assetMap[$asset['item_id']] = $asset;
        }

        // Pre-load all type names in bulk to avoid N+1 queries per asset.
        $allTypeIds = [];
        $seenTypeIds = [];
        foreach ($rawAssets as $asset) {
            $id = $asset['type_id'] ?? 0;
            if ($id > 0 && ! isset($seenTypeIds[$id])) {
                $allTypeIds[] = $id;
                $seenTypeIds[$id] = true;
            }
        }
        $typeNameCache = [];
        if (! empty($allTypeIds)) {
            try {
                EveType::whereIn('type_id', $allTypeIds)
                    ->get(['type_id', 'name'])
                    ->each(function ($t) use (&$typeNameCache) {
                        $typeNameCache[$t->type_id] = $t->name;
                    });
            } catch (\Exception $e) {
                Log::debug('AssetService: Could not bulk-load type names, falling back to per-type lookups.', ['error' => $e->getMessage()]);
            }
        }

        // Collect singleton item IDs to fetch custom names (containers, ships, etc.)
        $singletonIds = [];
        foreach ($rawAssets as $asset) {
            if (! empty($asset['is_singleton'])) {
                $singletonIds[] = $asset['item_id'];
            }
        }

        // Fetch custom names from ESI
        $customNames = [];
        if (! empty($singletonIds)) {
            try {
                if ($ownerType === 'corporate') {
                    $customNames = $this->esi->getCorporationAssetNames($character, $character->corporation_id, $singletonIds);
                } else {
                    $customNames = $this->esi->getCharacterAssetNames($character, $singletonIds);
                }
            } catch (\App\Services\Esi\EsiRequestException $e) {
                $this->snapshotEnrichmentPending = true;
                Log::info('Asset names enrichment deferred', ['character_id' => $character->character_id, 'outcome' => $e->outcome]);
            }
        }

        $locationNames = [];
        foreach ($rawAssets as $asset) {
            $typeId = $asset['type_id'] ?? 0;
            $typeName = $typeNameCache[$typeId] ??= $this->resolveTypeName($typeId);

            $locationFlag = $asset['location_flag'] ?? 'Unknown';
            $locationType = $asset['location_type'] ?? '';

            // Use friendly names for corporation hangar flags
            $locationFlag = self::CORP_HANGAR_FLAGS[$locationFlag] ?? $locationFlag;

            // Track parent relationship for tree building
            $parentItemId = ($locationType === 'item') ? ($asset['location_id'] ?? null) : null;

            $locationKey = $locationType.':'.($asset['location_id'] ?? 0);
            $locationName = $locationNames[$locationKey] ??= $this->resolveAssetLocationName(
                $asset['location_id'] ?? 0,
                $locationType,
                $assetMap,
                $structureCharacters,
                $typeNameCache
            );

            $itemId = $asset['item_id'] ?? 0;

            $enriched[] = [
                'item_id' => $itemId,
                'type_id' => $typeId,
                'type_name' => $typeName,
                'name' => $customNames[$itemId] ?? null,
                'quantity' => $asset['quantity'] ?? 0,
                'location_id' => $asset['location_id'] ?? 0,
                'location_name' => $locationName,
                'location_flag' => $locationFlag,
                'character_id' => $character->character_id,
                'character_name' => $character->name,
                'owner_type' => $ownerType,
                'is_singleton' => $asset['is_singleton'] ?? false,
                'corporation_name' => $ownerType === 'corporate' ? ($character->corporation_name ?? 'Unknown Corp') : null,
                'parent_item_id' => $parentItemId,
            ];
        }

        return $enriched;
    }

    /**
     * Resolve a type_id to its name.
     */
    protected function resolveTypeName(int $typeId): string
    {
        if ($typeId <= 0) {
            return 'Unknown';
        }

        // Try local SDE first
        try {
            $type = EveType::find($typeId);
            if ($type) {
                return $type->name;
            }
        } catch (\Exception $e) {
            // Table may not exist
        }

        // Fall back to ESI
        $typeInfo = $this->esi->getTypeInfo($typeId);

        return $typeInfo['name'] ?? "Type #{$typeId}";
    }

    /**
     * Resolve an asset's location to a human-readable name.
     *
     * @param  array  $structureCharacters  Characters with esi-universe.read_structures.v1 scope
     * @param  array  $typeNameCache  Pre-loaded type_id => name map to avoid repeated DB hits
     */
    protected function resolveAssetLocationName(int $locationId, string $locationType, array $assetMap, array $structureCharacters = [], array $typeNameCache = [], array $visited = []): string
    {
        if ($locationId <= 0) {
            return 'Unknown Location';
        }

        // If the location_type is 'item', asset is inside another item (container/ship)
        if ($locationType === 'item' && isset($assetMap[$locationId])) {
            if (isset($visited[$locationId])) {
                throw new \App\Services\Esi\EsiRequestException('incomplete', 30);
            }
            $visited[$locationId] = true;
            $parent = $assetMap[$locationId];
            $parentTypeId = $parent['type_id'] ?? 0;
            $parentName = $typeNameCache[$parentTypeId] ?? $this->resolveTypeName($parentTypeId);
            // Recursively resolve the parent's location
            $parentLocation = $this->resolveAssetLocationName(
                $parent['location_id'] ?? 0,
                $parent['location_type'] ?? '',
                $assetMap,
                $structureCharacters,
                $typeNameCache,
                $visited
            );

            return "{$parentName} in {$parentLocation}";
        }

        // NPC stations (IDs NPC_STATION_ID_MIN–NPC_STATION_ID_MAX)
        if ($locationId >= self::NPC_STATION_ID_MIN && $locationId < self::NPC_STATION_ID_MAX) {
            // Try SDE station data first
            try {
                $sdeStation = SdeStation::find($locationId);
                if ($sdeStation && $sdeStation->station_name) {
                    return $sdeStation->station_name;
                }
            } catch (\Exception $e) {
                // Table may not exist
            }

            $station = $this->esi->getStation($locationId);
            if ($station && isset($station['name'])) {
                return $station['name'];
            }
        }

        // Solar systems (IDs SOLAR_SYSTEM_ID_MIN–SOLAR_SYSTEM_ID_MAX)
        if ($locationId >= self::SOLAR_SYSTEM_ID_MIN && $locationId < self::SOLAR_SYSTEM_ID_MAX) {
            $system = $this->esi->getSystem($locationId);
            if ($system && isset($system['name'])) {
                return $system['name'];
            }
        }

        // Player structures (large IDs above threshold)
        if ($locationId > self::PLAYER_STRUCTURE_ID_THRESHOLD) {
            // Skip structures we've recently failed to resolve (avoid hammering ESI)
            $failCacheKey = "esi.structure.fail.{$locationId}";
            if (! Cache::has($failCacheKey)) {
                foreach ($structureCharacters as $structChar) {
                    $structure = $this->esi->getStructure($locationId, $structChar);
                    if ($structure && isset($structure['name'])) {
                        $name = $structure['name'];

                        // Append the solar system name if available
                        if (isset($structure['solar_system_id'])) {
                            $system = $this->esi->getSystem($structure['solar_system_id']);
                            if ($system && isset($system['name'])) {
                                $name .= " ({$system['name']})";
                            }
                        }

                        return $name;
                    }
                }

                // Fallback: try the public POST /universe/names/ endpoint
                $names = $this->esi->resolveUniverseNames([$locationId]);
                foreach ($names as $entry) {
                    if (isset($entry['id'], $entry['name']) && (int) $entry['id'] === $locationId) {
                        return $entry['name'];
                    }
                }

                // Cache the failure for 10 minutes to avoid repeated API calls
                Cache::put($failCacheKey, true, self::STRUCTURE_FAIL_CACHE_TTL);
            }

            return "Structure #{$locationId}";
        }

        return "Location #{$locationId}";
    }
}
