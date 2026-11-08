<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Models\EveType;
use App\Models\PlayerOwnedStructure;
use App\Models\SdeStation;
use App\Models\StructureCharacterAccess;
use App\Services\EsiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Universe ESI endpoints: types, dogma, images, stations, systems, structures, names and ids, search and groups.
 */
class UniverseClient
{
    public function __construct(
        protected EsiHttpClient $http,
    ) {}

    public function getTypeInfo(int $typeId): ?array
    {
        // 1. Check local SDE data first (populated by sde:import)
        try {
            $local = EveType::find($typeId);
            if ($local) {
                return [
                    'type_id' => $local->type_id,
                    'name' => $local->name,
                    'description' => $local->description ?? '',
                    'group_id' => $local->group_id,
                    'market_group_id' => $local->market_group_id,
                    'volume' => $local->volume,
                    'packaged_volume' => $local->packaged_volume,
                    'mass' => $local->mass,
                    'capacity' => $local->capacity,
                    'portion_size' => $local->portion_size,
                    'icon_id' => $local->icon_id,
                    'graphic_id' => $local->graphic_id,
                    'published' => $local->published,
                ];
            }
        } catch (\Exception $e) {
            // Table may not exist yet; fall through to ESI
        }

        // 2. Fall back to ESI API (cache for 30 days)
        return Cache::remember("esi.type.{$typeId}", EsiService::SDE_CACHE_TTL, function () use ($typeId) {
            $response = $this->http->publicRequest('get', "/universe/types/{$typeId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Get dogma attributes for a dynamic (abyssal/mutated) item.
     * Uses /dogma/dynamic/items/{type_id}/{item_id}/.
     * Requires scope: esi-assets.read_assets.v1
     */
    public function getDynamicItem(int $typeId, int $itemId, Character $character): ?array
    {
        $cacheKey = "esi.dynamic_item.{$itemId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->http->authenticatedRequest(
            'get',
            "/dogma/dynamic/items/{$typeId}/{$itemId}/",
            $character
        );

        if ($result !== null) {
            // Cache dynamic item data for 1 hour; will refresh on next asset refresh
            Cache::put($cacheKey, $result, 3600);
        }

        return $result;
    }

    /**
     * Get dogma attributes for a type (base/static stats).
     * Uses the public /universe/types/{type_id}/ endpoint which includes dogma_attributes.
     */
    public function getDogmaTypeAttributes(int $typeId): ?array
    {
        return Cache::remember("esi.dogma.type.{$typeId}", EsiService::SDE_CACHE_TTL, function () use ($typeId) {
            $response = $this->http->publicRequest('get', "/universe/types/{$typeId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Get dogma attribute metadata (name, high_is_good, unit_id, etc.).
     * Uses the public /dogma/attributes/{attribute_id}/ endpoint.
     */
    public function getDogmaAttribute(int $attributeId): ?array
    {
        return Cache::remember("esi.dogma.attribute.{$attributeId}", EsiService::SDE_CACHE_TTL, function () use ($attributeId) {
            $response = $this->http->publicRequest('get', "/dogma/attributes/{$attributeId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    public function getBestTypeImageUrl(int $typeId): ?string
    {
        $cacheKey = "esi.type_image_best.{$typeId}";

        // Cache successful image URL probes for 30 days to avoid repeated remote requests
        return Cache::remember($cacheKey, EsiService::SDE_CACHE_TTL, function () use ($typeId) {
            // try common styles and sizes in order
            $candidates = [
                ['style' => 'icon', 'px' => 64],
                ['style' => 'bp', 'px' => 64],
                ['style' => 'bpc', 'px' => 64],
                ['style' => 'render', 'px' => 512],
                ['style' => 'icon', 'px' => 32],
            ];

            foreach ($candidates as $c) {
                $url = EsiService::getTypeImageUrl($typeId, $c['style'], $c['px']);
                try {
                    // Prefer HEAD to be lightweight, but fall back to GET when HEAD is not allowed (405)
                    $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ESI-Image-Checker/1.0)'])->head($url);
                    if ($resp->successful()) {
                        return $url;
                    }

                    // If HEAD returned 405 Method Not Allowed (or not successful), try GET as some servers only allow GET
                    if (in_array($resp->status(), [405, 501]) || ! $resp->successful()) {
                        try {
                            $getResp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ESI-Image-Checker/1.0)'])->get($url);
                            if ($getResp->successful()) {
                                return $url;
                            }
                        } catch (\Exception $e) {
                            Log::warning("GET probe failed for image URL {$url}: ".$e->getMessage());
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning("Error probing image URL {$url}: ".$e->getMessage());
                    // try GET as a fallback
                    try {
                        $getResp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ESI-Image-Checker/1.0)'])->get($url);
                        if ($getResp->successful()) {
                            return $url;
                        }
                    } catch (\Exception $ex) {
                        Log::debug("Fallback GET probe failed for {$url}: ".$ex->getMessage());
                    }
                }
            }

            return null;
        });
    }

    public function typeImageExists(int $typeId, string $style = 'icon', int $px = 32): bool
    {
        $url = EsiService::getTypeImageUrl($typeId, $style, $px);
        $cacheKey = "esi.type_image_exists.{$typeId}.{$style}.{$px}";

        // Cache existence checks for 30 days to avoid reprobing remote image endpoints
        return Cache::remember($cacheKey, EsiService::SDE_CACHE_TTL, function () use ($url, $typeId) {
            try {
                $resp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ESI-Image-Checker/1.0)'])->head($url);
                if ($resp->successful()) {
                    return true;
                }

                if (in_array($resp->status(), [405, 501]) || ! $resp->successful()) {
                    // Try GET if HEAD isn't supported
                    try {
                        $getResp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ESI-Image-Checker/1.0)'])->get($url);

                        return $getResp->successful();
                    } catch (\Exception $e) {
                        Log::warning("GET probe failed for image {$url}: ".$e->getMessage());

                        return false;
                    }
                }

                return false;
            } catch (\Exception $e) {
                Log::warning("Failed to HEAD image for type {$typeId} at {$url}: ".$e->getMessage());
                // final fallback: try GET
                try {
                    $getResp = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; ESI-Image-Checker/1.0)'])->get($url);

                    return $getResp->successful();
                } catch (\Exception $ex) {
                    Log::warning("Fallback GET probe failed for image {$url}: ".$ex->getMessage());

                    return false;
                }
            }
        });
    }

    public function getStation(int $stationId): ?array
    {
        // Cache station metadata for 30 days
        return Cache::remember("esi.station.{$stationId}", EsiService::SDE_CACHE_TTL, function () use ($stationId) {
            // Check the local SDE table before hitting ESI
            $sdeStation = SdeStation::find($stationId);
            if ($sdeStation && $sdeStation->station_name) {
                return [
                    'station_id' => $sdeStation->station_id,
                    'name' => $sdeStation->station_name,
                    'solar_system_id' => $sdeStation->solar_system_id,
                ];
            }

            $response = $this->http->publicRequest('get', "/universe/stations/{$stationId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    public function getSystem(int $systemId): ?array
    {
        // Cache system metadata for 30 days
        return Cache::remember("esi.system.{$systemId}", EsiService::SDE_CACHE_TTL, function () use ($systemId) {
            $response = $this->http->publicRequest('get', "/universe/systems/{$systemId}/");

            return $response?->successful() ? $response->json() : null;
        });
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
        $characterId = $character->character_id;
        $cacheKey = "esi.structure.{$structureId}.char.{$characterId}";
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Check the persistent structure DB table before applying the denial
        // gate: if another character has already fetched this structure, serve
        // the persisted data without an ESI call for any character.
        $dbRecord = PlayerOwnedStructure::find($structureId);
        if ($dbRecord !== null && $dbRecord->name !== null
            && $dbRecord->updated_at?->gte(now()->subSeconds(EsiService::STRUCTURE_CACHE_TTL))) {
            $result = $dbRecord->only([
                'name', 'owner_id', 'solar_system_id', 'type_id',
                'position_x', 'position_y', 'position_z',
            ]);
            // Re-populate the fast per-character cache so subsequent in-process lookups skip the DB
            Cache::put($cacheKey, $result, EsiService::STRUCTURE_CACHE_TTL);

            return $result;
        }

        // Check the per-character access record before hitting ESI.
        // The denial is suppressed for STRUCTURE_ACCESS_DENIED_TTL (60 days).
        $accessRecord = StructureCharacterAccess::where('structure_id', $structureId)
            ->where('character_id', $characterId)
            ->first();

        if ($accessRecord !== null &&
            $accessRecord->access_denied_at !== null &&
            $accessRecord->access_denied_at->addSeconds(EsiService::STRUCTURE_ACCESS_DENIED_TTL)->isFuture()) {
            return null;
        }

        try {
            $result = $this->http->authenticatedResponse(
                'get', "/universe/structures/{$structureId}/", $character
            )->data();
        } catch (EsiRequestException $e) {
            // A timeout, throttle or invalid credential is not evidence of a
            // docking denial. Only persist the actual upstream 403.
            if ($e->response?->status() === 403) {
                StructureCharacterAccess::updateOrCreate(
                    ['structure_id' => $structureId, 'character_id' => $characterId],
                    ['access_denied_at' => now()]
                );
            }

            return null;
        }

        if ($result !== null) {
            Cache::put($cacheKey, $result, EsiService::STRUCTURE_CACHE_TTL);

            // Persist structure data to the shared DB table for future lookups
            PlayerOwnedStructure::updateOrCreate(
                ['structure_id' => $structureId],
                [
                    'name' => $result['name'] ?? null,
                    'owner_id' => $result['owner_id'] ?? null,
                    'solar_system_id' => $result['solar_system_id'] ?? null,
                    'type_id' => $result['type_id'] ?? null,
                    'position_x' => $result['position']['x'] ?? null,
                    'position_y' => $result['position']['y'] ?? null,
                    'position_z' => $result['position']['z'] ?? null,
                ]
            );

            // Clear any previous access-denied record for this character
            StructureCharacterAccess::updateOrCreate(
                ['structure_id' => $structureId, 'character_id' => $characterId],
                ['access_denied_at' => null]
            );
        }

        return $result;
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
        if (empty($ids)) {
            return [];
        }

        // Only send valid positive integers
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_int($id) && $id > 0)));
        if (empty($ids)) {
            return [];
        }

        $response = $this->http->publicRequest('post', '/universe/names/', $ids);

        return $response?->successful() ? ($response->json() ?? []) : [];
    }

    public function searchTypes(string $search, string $categories = 'inventory_type', bool $strict = false): ?array
    {
        // Use '1'/'0' instead of PHP's bool-to-string (''/true) for a predictable cache key.
        // Cache busting old entries (prior format used '' for false) is intentional: it ensures
        // any previously-null-poisoned entries are ignored and a fresh ESI call is made.
        $cacheKey = 'esi.search.'.md5($search).".{$categories}.".($strict ? '1' : '0');
        $cacheTtl = $categories === 'solar_system' ? EsiService::SDE_CACHE_TTL : 3600;

        // Use manual get/put to avoid caching null (failed ESI calls)
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = [
            'search' => $search,
            'categories' => $categories,
            'language' => 'en-us',
        ];
        // Only send 'strict' when true; omit it to use ESI's default (false)
        if ($strict) {
            $params['strict'] = 'true';
        }

        $response = $this->http->publicRequest('get', '/search/', $params);
        $result = $response?->successful() ? $response->json() : null;

        if ($result !== null) {
            Cache::put($cacheKey, $result, $cacheTtl);
        }

        return $result;
    }

    /**
     * Search EVE Online entities using the authenticated GET /characters/{id}/search/ endpoint.
     * Requires a valid character with an active ESI token (scope: esi-search.search_structures.v1).
     * Returns the raw ESI response array (e.g. ['corporation' => [123, 456]]) or null on failure.
     * Results are cached for 1 hour per character/query/categories combination.
     */
    public function searchCharacter(Character $character, string $search, string $categories = 'corporation', bool $strict = false): ?array
    {
        $cacheKey = "esi.char_search.{$character->character_id}.".md5($search).".{$categories}.".($strict ? '1' : '0');

        // Use manual get/put to avoid caching null (failed ESI calls)
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $params = [
            'categories' => $categories,
            'search' => $search,
            'language' => 'en-us',
        ];

        // Only send 'strict' when true; omit it to use ESI's default (false)
        if ($strict) {
            $params['strict'] = 'true';
        }

        $endpoint = "/characters/{$character->character_id}/search?".http_build_query($params);

        $result = $this->http->authenticatedRequest('get', $endpoint, $character);

        if ($result !== null) {
            Cache::put($cacheKey, $result, 3600);
        }

        return $result;
    }

    /**
     * Resolve a list of names to IDs using the public POST /universe/ids/ endpoint.
     * Returns categorised results (e.g. 'systems', 'stations', 'characters') or null on failure.
     */
    public function resolveUniverseIds(array $names): ?array
    {
        if (empty($names)) {
            return null;
        }

        $cacheKey = 'esi.universe_ids.'.md5(implode(',', $names));

        return Cache::remember($cacheKey, EsiService::SDE_CACHE_TTL, function () use ($names) {
            $response = $this->http->publicRequest('post', '/universe/ids/', $names);

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Get a page of type IDs from the universe/types endpoint.
     * Returns [typeIds, totalPages] or null on failure.
     */
    public function getUniverseTypeIds(int $page = 1): ?array
    {
        $response = $this->http->publicRequest('get', '/universe/types/', ['page' => $page]);

        if ($response === null) {
            return null;
        }

        if (! $response->successful()) {
            Log::error("ESI error fetching universe type IDs page {$page}: HTTP {$response->status()}");

            return null;
        }

        $totalPages = (int) ($response->header('X-Pages') ?? 1);

        return [
            'type_ids' => $response->json(),
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Fetch universe group info from the ESI API.
     *
     * Returns group data including name, category_id, and published status.
     * Results are cached for 30 days (SDE data).
     */
    public function getGroupInfo(int $groupId): ?array
    {
        return Cache::remember("esi.group.{$groupId}", EsiService::SDE_CACHE_TTL, function () use ($groupId) {
            $response = $this->http->publicRequest('get', "/universe/groups/{$groupId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Fetch universe category info from the ESI API.
     *
     * Returns category data including name and published status.
     * Results are cached for 30 days (SDE data).
     */
    public function getCategoryInfo(int $categoryId): ?array
    {
        return Cache::remember("esi.category.{$categoryId}", EsiService::SDE_CACHE_TTL, function () use ($categoryId) {
            $response = $this->http->publicRequest('get', "/universe/categories/{$categoryId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }
}
