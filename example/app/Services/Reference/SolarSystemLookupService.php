<?php

namespace App\Services\Reference;

use App\Models\SdeSolarSystem;
use App\Services\EsiService;
use Illuminate\Support\Collection;

/**
 * Solar-system lookups backed by the SDE (sde_solar_systems), with the ESI
 * fallbacks the reaction planner relies on.
 *
 * The six old /api/.../search-systems routes and /api/v1/systems all read
 * through here. The old routes differ, and keep their own behaviour:
 *  - legacySearch(): substring match, {results: [...]} with security rounded
 *    to 1 dp, optional lookup by id (invention / reaction planner) and
 *    optional ESI fallback (reaction planner only);
 *  - prefixSearch(): wholemap's prefix match, raw rows including region_id.
 */
class SolarSystemLookupService
{
    public const MIN_QUERY_LENGTH = 2;

    public const SEARCH_LIMIT = 20;

    /** ESI fallback results are capped lower, as before. */
    private const ESI_FALLBACK_LIMIT = 10;

    public function __construct(private EsiService $esi) {}

    public function find(int $solarSystemId): ?SdeSolarSystem
    {
        return SdeSolarSystem::find($solarSystemId);
    }

    /**
     * Systems whose name contains $query (LIKE wildcards escaped), by name.
     *
     * @param  list<string>  $columns
     * @return Collection<int, SdeSolarSystem>
     */
    public function search(string $query, int $limit = self::SEARCH_LIMIT, array $columns = ['*']): Collection
    {
        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return new Collection;
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);

        return SdeSolarSystem::where('solar_system_name', 'like', '%'.$escaped.'%')
            ->orderBy('solar_system_name')
            ->limit($limit)
            ->get($columns)
            ->toBase();
    }

    /**
     * Wholemap typeahead: names STARTING with $query (wildcards not escaped),
     * returning only solar_system_id, solar_system_name, region_id, security.
     *
     * @return Collection<int, SdeSolarSystem>
     */
    public function prefixSearch(string $query): Collection
    {
        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return new Collection;
        }

        return SdeSolarSystem::where('solar_system_name', 'like', $query.'%')
            ->orderBy('solar_system_name')
            ->limit(self::SEARCH_LIMIT)
            ->get(['solar_system_id', 'solar_system_name', 'region_id', 'security'])
            ->toBase();
    }

    /**
     * The {results: [...]} list used by the old substring routes.
     *
     * With $solarSystemId set, returns just that system (or nothing) and
     * ignores $query. With $esiFallback, an unknown id or a name search with
     * no SDE match is retried against ESI (reaction planner behaviour).
     *
     * @return list<array{solar_system_id: int, solar_system_name: string, security: float}>
     */
    public function legacySearch(int $solarSystemId, string $query, bool $esiFallback = false): array
    {
        if ($solarSystemId) {
            $system = $this->find($solarSystemId);
            if ($system) {
                return [$this->legacyRow($system)];
            }

            if ($esiFallback) {
                $esiSystem = $this->esi->getSystem($solarSystemId);
                if ($esiSystem) {
                    return [$this->legacyEsiRow($esiSystem)];
                }
            }

            return [];
        }

        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $systems = $this->search($query, self::SEARCH_LIMIT, ['solar_system_id', 'solar_system_name', 'security']);

        if ($systems->isEmpty() && $esiFallback) {
            return $this->searchViaEsi($query);
        }

        return $systems->map(fn (SdeSolarSystem $s) => $this->legacyRow($s))->values()->all();
    }

    /**
     * @return array{solar_system_id: int, solar_system_name: string, security: float}
     */
    public function legacyRow(SdeSolarSystem $system): array
    {
        return [
            'solar_system_id' => $system->solar_system_id,
            'solar_system_name' => $system->solar_system_name,
            'security' => round($system->security ?? 0, 1),
        ];
    }

    /**
     * @param  array<string, mixed>  $system  ESI /universe/systems/{id}/ payload
     * @return array{solar_system_id: int, solar_system_name: string, security: float}
     */
    private function legacyEsiRow(array $system): array
    {
        return [
            'solar_system_id' => $system['system_id'],
            'solar_system_name' => $system['name'],
            'security' => round($system['security_status'] ?? 0, 1),
        ];
    }

    /**
     * ESI fallback when the SDE has no match: exact name via POST
     * /universe/ids/, then partial matches via GET /search/.
     *
     * @return list<array{solar_system_id: int, solar_system_name: string, security: float}>
     */
    private function searchViaEsi(string $query): array
    {
        $resolved = $this->esi->resolveUniverseIds([$query]);
        if ($resolved && ! empty($resolved['systems'])) {
            $results = [];
            foreach (array_slice($resolved['systems'], 0, self::ESI_FALLBACK_LIMIT) as $entry) {
                $system = $this->esi->getSystem($entry['id']);
                if ($system) {
                    $results[] = $this->legacyEsiRow($system);
                }
            }
            if (! empty($results)) {
                return $results;
            }
        }

        $searchResult = $this->esi->searchTypes($query, 'solar_system');
        if (! $searchResult || empty($searchResult['solar_system'])) {
            return [];
        }

        $results = [];
        foreach (array_slice($searchResult['solar_system'], 0, self::ESI_FALLBACK_LIMIT) as $systemId) {
            $system = $this->esi->getSystem($systemId);
            if ($system) {
                $results[] = $this->legacyEsiRow($system);
            }
        }

        return $results;
    }
}
