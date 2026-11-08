<?php

namespace App\Services;

use App\Models\BlueprintActivity;
use App\Models\Character;
use App\Services\Esi\CharacterClient;
use App\Services\Esi\CorporationClient;
use App\Services\Esi\EsiAuthClient;
use App\Services\Esi\EsiHttpClient;
use App\Services\Esi\IndustryClient;
use App\Services\Esi\MarketClient;
use App\Services\Esi\PlanetaryClient;
use App\Services\Esi\SdeClient;
use App\Services\Esi\UniverseClient;
use Illuminate\Support\Facades\Cache;

/**
 * Facade over the focused ESI clients in App\Services\Esi.
 *
 * Every public method keeps its original signature and delegates to the
 * client that owns the behaviour.  The clients are resolved lazily from the
 * container, so `new EsiService` with no arguments keeps working.
 */
class EsiService
{
    /** @var array<class-string, object> */
    protected array $clients = [];

    protected ?EsiRateLimiter $rateLimiter = null;

    public function __construct(?EsiRateLimiter $rateLimiter = null)
    {
        $this->rateLimiter = $rateLimiter;
    }

    /**
     * Resolve (and memoise) one of the focused ESI clients.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function client(string $class): object
    {
        if (isset($this->clients[$class])) {
            return $this->clients[$class];
        }

        // A custom rate limiter (only ever passed explicitly) is threaded
        // through the HTTP client; the container ignores unknown parameters.
        $params = [];
        if ($this->rateLimiter !== null && $class !== EsiHttpClient::class) {
            $params['http'] = $this->client(EsiHttpClient::class);
        } elseif ($this->rateLimiter !== null) {
            $params['rateLimiter'] = $this->rateLimiter;
        }

        return $this->clients[$class] = app()->make($class, $params);
    }

    /** Cache TTL for static SDE/type data: 30 days in seconds. */
    public const SDE_CACHE_TTL = 2592000;

    /** Cache TTL for market order data: 2 hours in seconds. */
    public const REGION_ORDERS_CACHE_TTL = 7200;

    /**
     * Stop sending requests when the ESI error-limit budget drops to
     * this threshold or below.  The header starts at 100 and counts
     * down with every error response.
     */
    public const ESI_ERROR_LIMIT_THRESHOLD = 20;

    /** Default assumed reset window (seconds) when no header is present. */
    public const ESI_ERROR_DEFAULT_RESET_SECONDS = 60;

    /**
     * How long (seconds) to suppress ESI calls for a structure after
     * receiving a 403 / access-denied response.  60 days.
     * 60 * 24 * 60 * 60 = 5 184 000
     */
    public const STRUCTURE_ACCESS_DENIED_TTL = 5184000;

    /**
     * Cache TTL (seconds) for per-character, per-structure access results: 24 hours.
     */
    public const STRUCTURE_CACHE_TTL = 86400;

    /**
     * Read the X-Esi-Error-Limit-Remain / X-Esi-Error-Limit-Reset headers
     * from any ESI response and persist the values so that all workers can
     * see the current error budget.
     *
     * Pass a $characterId for authenticated requests so the budget is tracked
     * per-character (ESI enforces the error budget on a per-character basis
     * for authenticated endpoints).  Omit it for public/IP-level endpoints.
     */
    public function trackEsiErrorLimit(\Illuminate\Http\Client\Response $response, ?int $characterId = null): void
    {
        $this->client(EsiHttpClient::class)->trackEsiErrorLimit($response, $characterId);
    }

    /**
     * Return true when the ESI error budget is too low and requests
     * should be skipped until the window resets.
     *
     * Pass a $characterId to check the per-character budget (used for
     * authenticated requests).  Omit it to check the global/IP-level budget.
     */
    public function isEsiErrorLimited(?int $characterId = null): bool
    {
        return $this->client(EsiHttpClient::class)->isEsiErrorLimited($characterId);
    }

    /**
     * Seconds until the current ESI error window resets (0 if unknown).
     *
     * Pass a $characterId to query the per-character window.
     */
    public function esiErrorResetSeconds(?int $characterId = null): int
    {
        return $this->client(EsiHttpClient::class)->esiErrorResetSeconds($characterId);
    }

    public function getCharacter(int $characterId): ?array
    {
        return $this->client(CharacterClient::class)->getCharacter($characterId);
    }

    public function getCorporation(int $corporationId, bool $ignoreCache = false): ?array
    {
        return $this->client(CorporationClient::class)->getCorporation($corporationId, $ignoreCache);
    }

    public function getAlliance(int $allianceId, bool $ignoreCache = false): ?array
    {
        return $this->client(CorporationClient::class)->getAlliance($allianceId, $ignoreCache);
    }

    /**
     * Get character affiliation (corporation_id, alliance_id) via ESI.
     * Uses the POST /characters/affiliation/ endpoint.
     */
    public function getCharacterAffiliation(int $characterId, bool $ignoreCache = false): ?array
    {
        return $this->client(CharacterClient::class)->getCharacterAffiliation($characterId, $ignoreCache);
    }

    /**
     * Alias for getCorporation for consistency with Character model usage.
     */
    public function getCorporationInfo(int $corporationId): ?array
    {
        return $this->client(CorporationClient::class)->getCorporationInfo($corporationId);
    }

    /**
     * Alias for getAlliance for consistency with Character model usage.
     */
    public function getAllianceInfo(int $allianceId): ?array
    {
        return $this->client(CorporationClient::class)->getAllianceInfo($allianceId);
    }

    public function revokeToken(string $token): void
    {
        $this->client(EsiAuthClient::class)->revokeToken($token);
    }

    public function refreshToken(string $refreshToken): ?array
    {
        return $this->client(EsiAuthClient::class)->refreshToken($refreshToken);
    }

    public function refreshCharacterToken(Character $character): bool
    {
        return $this->client(EsiAuthClient::class)->refreshCharacterToken($character);
    }

    public function getCharacterSkills(Character $character, bool $ignoreCache = false): ?array
    {
        return $this->client(CharacterClient::class)->getCharacterSkills($character, $ignoreCache);
    }

    public function getCharacterIndustryJobs(Character $character, bool $includeCompleted = false): ?array
    {
        return $this->client(IndustryClient::class)->getCharacterIndustryJobs($character, $includeCompleted);
    }

    /**
     * Get corporation industry jobs from ESI.
     * Requires scope: esi-industry.read_corporation_jobs.v1
     */
    public function getCorporationIndustryJobs(Character $character, int $corporationId, bool $includeCompleted = false): ?array
    {
        return $this->client(IndustryClient::class)->getCorporationIndustryJobs($character, $corporationId, $includeCompleted);
    }

    /**
     * Get character blueprints from ESI.
     * Requires scope: esi-characters.read_blueprints.v1
     */
    public function getCharacterBlueprints(Character $character): array
    {
        return $this->client(CharacterClient::class)->getCharacterBlueprints($character);
    }

    /**
     * Get character assets from ESI.
     * Requires scope: esi-assets.read_assets.v1
     *
     * Empty results are NOT cached so that a transient ESI failure does not
     * lock the character into an empty state for the entire cache TTL.
     */
    public function getCharacterAssets(Character $character): array
    {
        return $this->client(CharacterClient::class)->getCharacterAssets($character);
    }

    /**
     * Get character's corporation roles from ESI.
     * Requires scope: esi-characters.read_corporation_roles.v1
     */
    public function getCharacterRoles(Character $character): ?array
    {
        return $this->client(CharacterClient::class)->getCharacterRoles($character);
    }

    /**
     * Get corporation blueprints from ESI.
     * Requires scope: esi-corporations.read_blueprints.v1 and Director role.
     */
    public function getCorporationBlueprints(Character $character, int $corporationId): array
    {
        return $this->client(CorporationClient::class)->getCorporationBlueprints($character, $corporationId);
    }

    /**
     * Get corporation assets from ESI.
     * Requires scope: esi-assets.read_corporation_assets.v1 and Director role.
     *
     * Empty results are NOT cached so a different character with the proper
     * role can retry — ESI returns 403 (empty) when the character lacks the
     * required in-game role, but another character on the same account may
     * have it.
     */
    public function getCorporationAssets(Character $character, int $corporationId): array
    {
        return $this->client(CorporationClient::class)->getCorporationAssets($character, $corporationId);
    }

    /**
     * Get custom names for character assets (containers, ships, etc.).
     * Requires scope: esi-assets.read_assets.v1
     *
     * @param  array<int>  $itemIds  Item IDs to look up names for
     * @return array<int, string> Map of item_id => custom name
     */
    public function getCharacterAssetNames(Character $character, array $itemIds): array
    {
        return $this->client(CharacterClient::class)->getCharacterAssetNames($character, $itemIds);
    }

    /**
     * Get custom names for corporation assets (containers, ships, etc.).
     * Requires scope: esi-assets.read_corporation_assets.v1
     *
     * @param  array<int>  $itemIds  Item IDs to look up names for
     * @return array<int, string> Map of item_id => custom name
     */
    public function getCorporationAssetNames(Character $character, int $corporationId, array $itemIds): array
    {
        return $this->client(CorporationClient::class)->getCorporationAssetNames($character, $corporationId, $itemIds);
    }

    public function clearCharacterCache(int $characterId): void
    {
        $this->client(CharacterClient::class)->clearCharacterCache($characterId);
    }

    public function clearCorporationIndustryJobsCache(int $corporationId): void
    {
        $this->client(IndustryClient::class)->clearCorporationIndustryJobsCache($corporationId);
    }

    /**
     * Get corporation wallet journal entries from ESI.
     * Requires scope: esi-wallet.read_corporation_wallets.v1
     *
     * @param  Character  $character  Character with corporate wallet access
     * @param  int  $corporationId  Corporation ID
     * @param  int  $division  Wallet division (1-7, default 1 = master wallet)
     */
    public function getCorporationWalletJournal(Character $character, int $corporationId, int $division = 1): array
    {
        return $this->client(CorporationClient::class)->getCorporationWalletJournal($character, $corporationId, $division);
    }

    /**
     * Clear cached corporation wallet journal entries.
     */
    public function clearCorporationWalletJournalCache(int $corporationId): void
    {
        $this->client(CorporationClient::class)->clearCorporationWalletJournalCache($corporationId);
    }

    /**
     * Get corporation wallet divisions from ESI.
     * Requires scope: esi-wallet.read_corporation_wallets.v1
     */
    public function getCorporationWallets(Character $character, int $corporationId): ?array
    {
        return $this->client(CorporationClient::class)->getCorporationWallets($character, $corporationId);
    }

    public function getTypeInfo(int $typeId): ?array
    {
        return $this->client(UniverseClient::class)->getTypeInfo($typeId);
    }

    /**
     * Get dogma attributes for a dynamic (abyssal/mutated) item.
     * Uses /dogma/dynamic/items/{type_id}/{item_id}/.
     * Requires scope: esi-assets.read_assets.v1
     */
    public function getDynamicItem(int $typeId, int $itemId, Character $character): ?array
    {
        return $this->client(UniverseClient::class)->getDynamicItem($typeId, $itemId, $character);
    }

    /**
     * Get dogma attributes for a type (base/static stats).
     * Uses the public /universe/types/{type_id}/ endpoint which includes dogma_attributes.
     */
    public function getDogmaTypeAttributes(int $typeId): ?array
    {
        return $this->client(UniverseClient::class)->getDogmaTypeAttributes($typeId);
    }

    /**
     * Get dogma attribute metadata (name, high_is_good, unit_id, etc.).
     * Uses the public /dogma/attributes/{attribute_id}/ endpoint.
     */
    public function getDogmaAttribute(int $attributeId): ?array
    {
        return $this->client(UniverseClient::class)->getDogmaAttribute($attributeId);
    }

    public static function getTypeImageUrl(int $typeId, string $style = 'icon', int $px = 32): string
    {
        $validStyles = ['icon', 'render', 'bp', 'bpc'];
        $style = in_array($style, $validStyles) ? $style : 'icon';

        // The image server answers 400 to /icon for blueprints; they only have bp/bpc.
        if ($style === 'icon' && self::isBlueprintType($typeId)) {
            $style = 'bp';
        }

        return "https://images.evetech.net/types/{$typeId}/{$style}?size={$px}";
    }

    /** @var array<int, true>|null Blueprint type ids from the SDE, memoised per process. */
    private static ?array $blueprintTypeIds = null;

    public static function isBlueprintType(int $typeId): bool
    {
        self::$blueprintTypeIds ??= Cache::remember(
            'esi.blueprint_type_ids',
            86400,
            fn () => BlueprintActivity::query()->distinct()->pluck('blueprint_type_id')->flip()->map(fn () => true)->all()
        );

        return isset(self::$blueprintTypeIds[$typeId]);
    }

    /** Drop the memoised blueprint ids (after an SDE import, and between tests). */
    public static function forgetBlueprintTypeIds(): void
    {
        self::$blueprintTypeIds = null;
        Cache::forget('esi.blueprint_type_ids');
    }

    public function getBestTypeImageUrl(int $typeId): ?string
    {
        return $this->client(UniverseClient::class)->getBestTypeImageUrl($typeId);
    }

    public function typeImageExists(int $typeId, string $style = 'icon', int $px = 32): bool
    {
        return $this->client(UniverseClient::class)->typeImageExists($typeId, $style, $px);
    }

    public function getStation(int $stationId): ?array
    {
        return $this->client(UniverseClient::class)->getStation($stationId);
    }

    public function getSystem(int $systemId): ?array
    {
        return $this->client(UniverseClient::class)->getSystem($systemId);
    }

    /**
     * Get a player-owned structure's info from ESI.
     * Requires scope: esi-universe.read_structures.v1
     *
     * Access tracking is per-character, per-structure and cached for
     * STRUCTURE_CACHE_TTL (24 hours).  Successful results are persisted
     * to the DB so future calls by the same character never hit ESI again
     * within the TTL window.  When ESI returns an error (e.g. 403 Forbidden)
     * the denial is recorded in `structure_character_access` for this
     * character and ESI calls are suppressed for STRUCTURE_ACCESS_DENIED_TTL
     * (60 days), reducing log noise and protecting the ESI error budget.
     *
     * The persistent DB table (`player_owned_structures`) is checked first;
     * if the structure is already known from a previous successful lookup by
     * any character, that data is returned immediately without hitting ESI,
     * regardless of any per-character denial record.
     */
    public function getStructure(int $structureId, Character $character): ?array
    {
        return $this->client(UniverseClient::class)->getStructure($structureId, $character);
    }

    /**
     * Resolve a set of IDs to names using the public ESI endpoint.
     *
     * Supports: Characters, Corporations, Alliances, Stations, Solar Systems,
     * Constellations, Regions, Types, Factions.
     *
     * @return array<int, array{id: int, name: string, category: string}>
     */
    public function resolveUniverseNames(array $ids): array
    {
        return $this->client(UniverseClient::class)->resolveUniverseNames($ids);
    }

    public function fetchPilotCurrentPosition(Character $pilot, bool $ignoreCache = false): ?array
    {
        return $this->client(CharacterClient::class)->fetchPilotCurrentPosition($pilot, $ignoreCache);
    }

    public function fetchPilotTrainingQueue(Character $pilot, bool $ignoreCache = false): ?array
    {
        return $this->client(CharacterClient::class)->fetchPilotTrainingQueue($pilot, $ignoreCache);
    }

    public function getMarketPrices(): ?array
    {
        return $this->client(MarketClient::class)->getMarketPrices();
    }

    /**
     * Get all market orders for a region from the database.
     *
     * Orders are populated by the FetchRegionOrders job which runs every
     * 20 minutes and stores results in the market_orders table.
     *
     * Returns an array of order arrays matching the ESI response format so
     * existing consumers (MarketTradingService, etc.) work unchanged.
     */
    public function getMarketRegionAllOrders(int $regionId): array
    {
        return $this->client(MarketClient::class)->getMarketRegionAllOrders($regionId);
    }

    /**
     * Lowest sell or highest buy price per type in a region's stored orders (see MarketClient).
     *
     * @return array<int, float>
     */
    public function getRegionBestPrices(int $regionId, bool $buyOrders): array
    {
        return $this->client(MarketClient::class)->getRegionBestPrices($regionId, $buyOrders);
    }

    /**
     * One page of every order in a region, straight from ESI (see MarketClient).
     */
    public function fetchRegionOrdersPage(int $regionId, int $page, ?string $etag = null): Esi\RegionOrdersPage
    {
        return $this->client(MarketClient::class)->fetchRegionOrdersPage($regionId, $page, $etag);
    }

    public function getMarketRegionOrders(int $regionId, int $typeId, string $orderType = 'sell'): ?array
    {
        return $this->client(MarketClient::class)->getMarketRegionOrders($regionId, $typeId, $orderType);
    }

    public function getMarketRegionHistory(int $regionId, int $typeId): ?array
    {
        return $this->client(MarketClient::class)->getMarketRegionHistory($regionId, $typeId);
    }

    /**
     * Fetch market history directly from ESI (used as fallback when DB has no data).
     */
    public function fetchMarketRegionHistoryFromEsi(int $regionId, int $typeId): ?array
    {
        return $this->client(MarketClient::class)->fetchMarketRegionHistoryFromEsi($regionId, $typeId);
    }

    public function getIndustrySystemCostIndices(): ?array
    {
        return $this->client(MarketClient::class)->getIndustrySystemCostIndices();
    }

    public function searchTypes(string $search, string $categories = 'inventory_type', bool $strict = false): ?array
    {
        return $this->client(UniverseClient::class)->searchTypes($search, $categories, $strict);
    }

    /**
     * Search EVE Online entities using the authenticated GET /characters/{id}/search/ endpoint.
     * Requires a valid character with an active ESI token (scope: esi-search.search_structures.v1).
     * Returns the raw ESI response array (e.g. ['corporation' => [123, 456]]) or null on failure.
     * Results are cached for 1 hour per character/query/categories combination.
     */
    public function searchCharacter(Character $character, string $search, string $categories = 'corporation', bool $strict = false): ?array
    {
        return $this->client(UniverseClient::class)->searchCharacter($character, $search, $categories, $strict);
    }

    /**
     * Resolve a list of names to IDs using the public POST /universe/ids/ endpoint.
     * Returns categorised results (e.g. 'systems', 'stations', 'characters') or null on failure.
     */
    public function resolveUniverseIds(array $names): ?array
    {
        return $this->client(UniverseClient::class)->resolveUniverseIds($names);
    }

    /**
     * Get a page of type IDs from the universe/types endpoint.
     * Returns [typeIds, totalPages] or null on failure.
     */
    public function getUniverseTypeIds(int $page = 1): ?array
    {
        return $this->client(UniverseClient::class)->getUniverseTypeIds($page);
    }

    /**
     * Fetch blueprint/schematic SDE data from the Fuzzwork SDE API.
     *
     * ESI does not expose manufacturing activities (materials, products, time) for
     * blueprints or reaction formulas. This data lives in the EVE Static Data Export
     * (SDE). The Fuzzwork API mirrors it in a convenient JSON format.
     *
     * Returns the full blueprint record keyed by typeID, e.g.:
     *   { "activities": { "1": { "materials": [...], "products": [...], "time": N }, ... } }
     *
     * Activity IDs: 1 = Manufacturing, 5 = Copying, 8 = Invention, 9 = Reaction
     */
    public function getBlueprintSdeData(int $typeId): ?array
    {
        return $this->client(SdeClient::class)->getBlueprintSdeData($typeId);
    }

    /**
     * Resolve SDE blueprint data into a normalised structure with material names.
     *
     * Checks the local blueprint_activities table first, then falls back to the
     * Fuzzwork SDE API if no local record exists.
     *
     * @param  int  $blueprintTypeId  The blueprint/formula type ID
     * @param  int  $activityId  Activity to resolve (1=manufacturing, 5=copying, 8=invention, 11=reaction)
     * @return array|null { materials: [...], products: [...], time: int } or null
     */
    public function resolveBlueprintActivity(int $blueprintTypeId, int $activityId = 1): ?array
    {
        return $this->client(SdeClient::class)->resolveBlueprintActivity($blueprintTypeId, $activityId);
    }

    /**
     * Get a character's planetary colonies from ESI.
     * Requires scope: esi-planets.manage_planets.v1
     */
    public function getCharacterPlanets(Character $character): ?array
    {
        return $this->client(PlanetaryClient::class)->getCharacterPlanets($character);
    }

    /**
     * Get full details of a character's planetary colony from ESI.
     * Requires scope: esi-planets.manage_planets.v1
     */
    public function getCharacterPlanetDetails(Character $character, int $planetId): ?array
    {
        return $this->client(PlanetaryClient::class)->getCharacterPlanetDetails($character, $planetId);
    }

    /**
     * Get planetary schematic info from ESI (public endpoint).
     */
    public function getPlanetarySchematic(int $schematicId): ?array
    {
        return $this->client(PlanetaryClient::class)->getPlanetarySchematic($schematicId);
    }

    /**
     * Get planet info from ESI (public endpoint).
     */
    public function getPlanetInfo(int $planetId): ?array
    {
        return $this->client(PlanetaryClient::class)->getPlanetInfo($planetId);
    }

    /**
     * Clear cached planetary interaction data for a character.
     */
    public function clearPlanetaryCache(int $characterId): void
    {
        $this->client(PlanetaryClient::class)->clearPlanetaryCache($characterId);
    }

    /**
     * Get reprocessing materials for a type.
     *
     * Checks the local type_materials table first (populated by sde:import),
     * then falls back to the Fuzzwork SDE API if not found locally.
     *
     * Returns an array of materials that the type yields when reprocessed at 100% yield,
     * e.g. [['type_id' => 34, 'name' => 'Tritanium', 'quantity' => 100], ...]
     *
     * @return array|null Materials array or null if the type has no reprocessing output.
     */
    public function getTypeMaterials(int $typeId): ?array
    {
        return $this->client(SdeClient::class)->getTypeMaterials($typeId);
    }

    /**
     * Batch-load reprocessing materials for multiple type IDs.
     *
     * Prefers the local type_materials DB table. For any type IDs not found
     * locally, falls back to the Fuzzwork SDE API and persists the results.
     * To prevent timeouts, API lookups are capped at a maximum number per call.
     *
     * @param  array<int>  $typeIds
     * @param  int  $apiLimit  Maximum number of API lookups per invocation
     * @return array<int, array|null> Keyed by type_id → materials array (or null)
     */
    public function getTypeMaterialsBatch(array $typeIds, int $apiLimit = 5): array
    {
        return $this->client(SdeClient::class)->getTypeMaterialsBatch($typeIds, $apiLimit);
    }

    /**
     * Get all corporation projects from ESI using cursor-based pagination.
     * Requires scope: esi-corporations.read_projects.v1
     */
    public function getCorporationProjects(Character $character, int $corporationId): array
    {
        return $this->client(CorporationClient::class)->getCorporationProjects($character, $corporationId);
    }

    /**
     * Get contributors for a specific corporation project.
     * Requires scope: esi-corporations.read_projects.v1
     */
    public function getCorporationProjectContributors(Character $character, int $corporationId, string $projectId): array
    {
        return $this->client(CorporationClient::class)->getCorporationProjectContributors($character, $corporationId, $projectId);
    }

    /**
     * Clear the cached corporation projects for a given corporation.
     */
    public function clearCorporationProjectsCache(int $corporationId): void
    {
        $this->client(CorporationClient::class)->clearCorporationProjectsCache($corporationId);
    }

    /**
     * Clear the cached contributors for a specific project.
     */
    public function clearCorporationProjectContributorsCache(int $corporationId, string $projectId): void
    {
        $this->client(CorporationClient::class)->clearCorporationProjectContributorsCache($corporationId, $projectId);
    }

    /**
     * Fetch universe group info from the ESI API.
     *
     * Returns group data including name, category_id, and published status.
     * Results are cached for 30 days (SDE data).
     */
    public function getGroupInfo(int $groupId): ?array
    {
        return $this->client(UniverseClient::class)->getGroupInfo($groupId);
    }

    /**
     * Fetch universe category info from the ESI API.
     *
     * Returns category data including name and published status.
     * Results are cached for 30 days (SDE data).
     */
    public function getCategoryInfo(int $categoryId): ?array
    {
        return $this->client(UniverseClient::class)->getCategoryInfo($categoryId);
    }

    /**
     * Fetch the list of all market group IDs from the ESI API.
     *
     * @return array<int>|null Array of market group IDs, or null on failure.
     */
    public function getMarketGroupIds(): ?array
    {
        return $this->client(MarketClient::class)->getMarketGroupIds();
    }

    /**
     * Fetch details for a specific market group from the ESI API.
     *
     * Returns market group data including name, description, parent_group_id, and types.
     * Results are cached for 30 days (SDE data).
     */
    public function getMarketGroupInfo(int $marketGroupId): ?array
    {
        return $this->client(MarketClient::class)->getMarketGroupInfo($marketGroupId);
    }
}
