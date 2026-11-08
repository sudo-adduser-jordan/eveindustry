<?php

namespace App\Services\Reference;

use App\Models\SdeStation;
use Illuminate\Support\Collection;

/**
 * NPC station lookups from the SDE (sde_stations). Shared by the invention
 * and reaction planner station searches and /api/v1/stations.
 */
class StationLookupService
{
    public const MIN_QUERY_LENGTH = 2;

    public const SEARCH_LIMIT = 20;

    public const LEGACY_COLUMNS = ['station_id', 'station_name', 'solar_system_id', 'security'];

    public function find(int $stationId): ?SdeStation
    {
        return SdeStation::find($stationId);
    }

    /**
     * Stations in a solar system, by name. $query narrows the list only when
     * it is at least 2 characters.
     *
     * @param  list<string>  $columns
     * @return Collection<int, SdeStation>
     */
    public function inSystem(int $solarSystemId, string $query = '', array $columns = ['*']): Collection
    {
        $stations = SdeStation::where('solar_system_id', $solarSystemId);

        if (strlen($query) >= self::MIN_QUERY_LENGTH) {
            $stations->where('station_name', 'like', '%'.$this->escape($query).'%');
        }

        return $stations->orderBy('station_name')->get($columns)->toBase();
    }

    /**
     * Up to $limit stations whose name contains $query, by name, optionally
     * within one solar system.
     *
     * @param  list<string>  $columns
     * @return Collection<int, SdeStation>
     */
    public function search(string $query, ?int $solarSystemId = null, int $limit = self::SEARCH_LIMIT, array $columns = ['*']): Collection
    {
        if (strlen($query) < self::MIN_QUERY_LENGTH) {
            return new Collection;
        }

        return SdeStation::where('station_name', 'like', '%'.$this->escape($query).'%')
            ->when($solarSystemId, fn ($q) => $q->where('solar_system_id', $solarSystemId))
            ->orderBy('station_name')
            ->limit($limit)
            ->get($columns)
            ->toBase();
    }

    /**
     * The {results: [...]} list used by the old planner routes: by station
     * id, else every station in a system (optionally filtered), else a name
     * search. $withParsedName adds moon / facility_name (reaction planner).
     *
     * @return list<array<string, mixed>>
     */
    public function legacySearch(int $stationId, int $solarSystemId, string $query, bool $withParsedName): array
    {
        if ($stationId) {
            $station = $this->find($stationId);

            return $station ? [$this->legacyRow($station, $withParsedName)] : [];
        }

        $stations = $solarSystemId
            ? $this->inSystem($solarSystemId, $query, self::LEGACY_COLUMNS)
            : $this->search($query, null, self::SEARCH_LIMIT, self::LEGACY_COLUMNS);

        return $stations->map(fn (SdeStation $s) => $this->legacyRow($s, $withParsedName))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function legacyRow(SdeStation $station, bool $withParsedName): array
    {
        $row = [
            'station_id' => $station->station_id,
            'station_name' => $station->station_name,
        ];

        if ($withParsedName) {
            $row += self::parseName($station->station_name);
        }

        return $row + [
            'solar_system_id' => $station->solar_system_id,
            'security' => round($station->security ?? 0, 1),
        ];
    }

    /**
     * Split a station name into its moon and facility parts, e.g.
     * "Jita IV - Moon 4 - Caldari Navy Assembly Plant" gives moon "Moon 4"
     * and facility_name "Caldari Navy Assembly Plant";
     * "Amarr VIII (Oris) - Emperor Family Academy" gives moon null.
     *
     * @return array{moon: string|null, facility_name: string}
     */
    public static function parseName(string $stationName): array
    {
        if (preg_match('/- (Moon \d+) - (.+)$/i', $stationName, $matches)) {
            return ['moon' => $matches[1], 'facility_name' => $matches[2]];
        }

        if (preg_match('/- ([^-]+)$/', $stationName, $matches)) {
            return ['moon' => null, 'facility_name' => trim($matches[1])];
        }

        return ['moon' => null, 'facility_name' => $stationName];
    }

    private function escape(string $query): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
    }
}
