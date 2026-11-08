<?php

namespace App\Services\Esi;

use App\Models\Character;
use App\Services\Esi\Diagnostics\EsiDiagnosticsContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Character-scoped ESI endpoints: public profile, affiliation, skills, roles, blueprints, assets and asset names.
 */
class CharacterClient
{
    public function __construct(
        protected EsiHttpClient $http,
        protected EsiAuthClient $auth,
    ) {}

    public function getCharacter(int $characterId): ?array
    {
        return Cache::remember("esi.character.{$characterId}", 3600, function () use ($characterId) {
            $response = $this->http->publicRequest('get', "/characters/{$characterId}/");

            return $response?->successful() ? $response->json() : null;
        });
    }

    /**
     * Get character affiliation (corporation_id, alliance_id) via ESI.
     * Uses the POST /characters/affiliation/ endpoint.
     */
    public function getCharacterAffiliation(int $characterId, bool $ignoreCache = false): ?array
    {
        $cacheKey = "esi.affiliation.{$characterId}";

        if ($ignoreCache) {
            Cache::forget($cacheKey);
        }

        // Check cache first
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        // Fetch from ESI
        $response = $this->http->publicRequest('post', '/characters/affiliation/', [$characterId]);
        if ($response?->successful()) {
            $data = $response->json();
            // The endpoint returns an array of affiliations; find ours
            foreach ($data as $affiliation) {
                if (isset($affiliation['character_id']) && $affiliation['character_id'] === $characterId) {
                    // Only cache successful responses for 1 hour
                    Cache::put($cacheKey, $affiliation, 3600);

                    return $affiliation;
                }
            }
        } elseif ($response !== null) {
            Log::warning("ESI failed to fetch affiliation for character {$characterId}: HTTP {$response->status()}");
        }

        return null;
    }

    public function getCharacterSkills(Character $character, bool $ignoreCache = false): ?array
    {
        $cacheKey = "esi.skills.{$character->character_id}";

        if ($ignoreCache) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, 3600, function () use ($character) {
            return $this->http->authenticatedResponse(
                'get',
                "/characters/{$character->character_id}/skills/",
                $character
            )->data();
        });
    }

    /**
     * Get character's corporation roles from ESI.
     * Requires scope: esi-characters.read_corporation_roles.v1
     */
    public function getCharacterRoles(Character $character): ?array
    {
        $cacheKey = "esi.roles.{$character->character_id}";

        return Cache::remember($cacheKey, 3600, function () use ($character) {
            return $this->http->authenticatedResponse(
                'get',
                "/characters/{$character->character_id}/roles/",
                $character
            )->data();
        });
    }

    /**
     * Get character blueprints from ESI.
     * Requires scope: esi-characters.read_blueprints.v1
     */
    public function getCharacterBlueprints(Character $character): array
    {
        $cacheKey = "esi.blueprints.{$character->character_id}";

        return Cache::remember($cacheKey, 3600, function () use ($character) {
            return $this->http->authenticatedPaginatedRequest(
                "/characters/{$character->character_id}/blueprints/",
                $character
            );
        }) ?? [];
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
        $cacheKey = "esi.assets.{$character->character_id}";

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $result = $this->http->authenticatedPaginatedRequest(
            "/characters/{$character->character_id}/assets/",
            $character
        );

        // Pagination throws on failure; an empty successful snapshot is valid.
        Cache::put($cacheKey, $result, 3600);

        return $result;
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
        if (empty($itemIds)) {
            return [];
        }

        try {
            $names = [];
            foreach (array_chunk($itemIds, EsiHttpClient::ESI_PAGE_SIZE) as $chunk) {
                $response = app(EsiDiagnosticsContext::class)->with(
                    ['dataset' => 'asset_names', 'batch_size' => count($chunk), 'batch_fingerprint' => EsiDiagnosticsContext::fingerprint($chunk)],
                    fn () => $this->http->authenticatedResponse(
                        'post', "/characters/{$character->character_id}/assets/names/",
                        $character, params: $chunk
                    ),
                );

                foreach ($response->data() ?? [] as $entry) {
                    $name = $entry['name'] ?? '';
                    if ($name !== '' && $name !== 'None') {
                        $names[$entry['item_id']] = $name;
                    }
                }
            }

            return $names;
        } catch (EsiRequestException $e) {
            // Preserve the failure so the snapshot can flag unfinished enrichment.
            throw $e;
        } catch (\Exception $e) {
            Log::error('ESI error fetching character asset names: '.$e->getMessage());

            return [];
        }
    }

    public function fetchPilotCurrentPosition(Character $pilot, bool $ignoreCache = false): ?array
    {
        return $this->http->authenticatedResponse(
            'get',
            "/characters/{$pilot->character_id}/location",
            $pilot
        )->data();
    }

    public function fetchPilotTrainingQueue(Character $pilot, bool $ignoreCache = false): ?array
    {
        return $this->http->authenticatedResponse(
            'get',
            "/characters/{$pilot->character_id}/skillqueue/",
            $pilot
        )->data();
    }

    public function clearCharacterCache(int $characterId): void
    {
        Cache::forget("esi.character.{$characterId}");
        Cache::forget("esi.skills.{$characterId}");
        // Clear both cached variants of industry jobs
        Cache::forget("esi.industry_jobs.{$characterId}.active");
        Cache::forget("esi.industry_jobs.{$characterId}.with_completed");
        // Also clear the older unsuffixed key if present
        Cache::forget("esi.industry_jobs.{$characterId}");
        // Clear blueprint and asset caches
        Cache::forget("esi.blueprints.{$characterId}");
        Cache::forget("esi.assets.{$characterId}");
        Cache::forget("esi.roles.{$characterId}");
    }
}
