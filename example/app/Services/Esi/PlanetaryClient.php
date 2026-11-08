<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Services\EsiService;
use Illuminate\Support\Facades\Cache;

/**
 * Planetary interaction ESI endpoints.
 */
class PlanetaryClient
{
    public function __construct(
        protected EsiHttpClient $http,
    ) {}

    /**
     * Get a character's planetary colonies from ESI.
     * Requires scope: esi-planets.manage_planets.v1
     */
    public function getCharacterPlanets(Character $character): ?array
    {
        $cacheKey = "esi.planets.{$character->character_id}";

        return Cache::remember($cacheKey, 600, function () use ($character) {
            return $this->http->authenticatedResponse(
                'get',
                "/characters/{$character->character_id}/planets/",
                $character
            )->data();
        });
    }

    /**
     * Get full details of a character's planetary colony from ESI.
     * Requires scope: esi-planets.manage_planets.v1
     */
    public function getCharacterPlanetDetails(Character $character, int $planetId): ?array
    {
        $cacheKey = "esi.planet_details.{$character->character_id}.{$planetId}";

        return Cache::remember($cacheKey, 600, function () use ($character, $planetId) {
            return $this->http->authenticatedResponse(
                'get',
                "/characters/{$character->character_id}/planets/{$planetId}/",
                $character
            )->data();
        });
    }

    /**
     * Get planetary schematic info from ESI (public endpoint).
     */
    public function getPlanetarySchematic(int $schematicId): ?array
    {
        return Cache::remember("esi.schematic.{$schematicId}", EsiService::SDE_CACHE_TTL, function () use ($schematicId) {
            $response = $this->http->publicRequest('get', "/universe/schematics/{$schematicId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Get planet info from ESI (public endpoint).
     */
    public function getPlanetInfo(int $planetId): ?array
    {
        return Cache::remember("esi.planet_info.{$planetId}", EsiService::SDE_CACHE_TTL, function () use ($planetId) {
            $response = $this->http->publicRequest('get', "/universe/planets/{$planetId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Clear cached planetary interaction data for a character.
     */
    public function clearPlanetaryCache(int $characterId): void
    {
        Cache::forget("esi.planets.{$characterId}");
        // Planet detail caches are keyed by character+planet, so we rely on TTL expiry for those
    }
}
