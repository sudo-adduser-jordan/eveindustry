<?php

namespace App\Services\Heavy;

use App\Models\PlayerOwnedStructure;
use App\Models\SdeSolarSystem;
use App\Models\SdeStation;
use App\Services\AssetService;
use App\Services\EsiService;

/**
 * Turns a list of ESI assets into the distinct root locations (stations and
 * structures) they sit in, with names. Shared by the personal and corporate
 * build-program asset-location endpoints.
 */
class AssetLocationResolver
{
    public function __construct(private EsiService $esi) {}

    /**
     * item_id => asset, for root-location resolution.
     *
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<int, array<string, mixed>>
     */
    public function assetMap(array $assets): array
    {
        $assetMap = [];
        foreach ($assets as $asset) {
            $assetMap[$asset['item_id'] ?? 0] = $asset;
        }

        return $assetMap;
    }

    /**
     * Walk up the asset parent chain to the station/structure the asset is in.
     * Assets with location_type 'item' sit inside another asset.
     *
     * @param  array<string, mixed>  $asset
     * @param  array<int, array<string, mixed>>  $assetMap
     */
    public function rootLocationId(array $asset, array $assetMap, int $depth = 0): int
    {
        // Guard against infinite loops in malformed data.
        if ($depth > 20) {
            return (int) ($asset['location_id'] ?? 0);
        }

        $locationType = $asset['location_type'] ?? '';
        $locationId = (int) ($asset['location_id'] ?? 0);

        if ($locationType === 'item' && isset($assetMap[$locationId])) {
            return $this->rootLocationId($assetMap[$locationId], $assetMap, $depth + 1);
        }

        return $locationId;
    }

    /**
     * Add the root location ids of $assets to $rootLocationIds (id => true).
     *
     * @param  array<int, array<string, mixed>>  $assets
     * @param  array<int, true>  $rootLocationIds
     */
    public function collectRoots(array $assets, array &$rootLocationIds): void
    {
        $assetMap = $this->assetMap($assets);
        foreach ($assets as $asset) {
            $rootId = $this->rootLocationId($asset, $assetMap);
            if ($rootId > 0) {
                $rootLocationIds[$rootId] = true;
            }
        }
    }

    /**
     * Name and solar system for each location id, sorted by system name then
     * location name; locations without a known system sort last ('~' follows
     * every letter in strcmp).
     *
     * @param  array<int, int>  $locationIds
     * @param  list<\App\Models\Character>  $structureCharacters  characters that may resolve player structures
     * @return list<array{location_id: int, name: string, solar_system_id: ?int, solar_system_name: ?string}>
     */
    public function describe(array $locationIds, array $structureCharacters): array
    {
        $locations = [];
        foreach ($locationIds as $locationId) {
            $name = null;
            $solarSystemId = null;

            if ($locationId >= 60000000 && $locationId < 64000000) {
                $station = SdeStation::find($locationId);
                if ($station) {
                    $name = $station->station_name;
                    $solarSystemId = $station->solar_system_id;
                }
            } elseif ($locationId > AssetService::PLAYER_STRUCTURE_ID_THRESHOLD) {
                // Player-owned structure: the DB first (filled by earlier ESI
                // lookups), then a live ESI call with a character that has the scope.
                $structure = PlayerOwnedStructure::find($locationId);
                if ($structure) {
                    $name = $structure->name;
                    $solarSystemId = $structure->solar_system_id;
                } elseif (! empty($structureCharacters)) {
                    foreach ($structureCharacters as $structChar) {
                        $esiStructure = $this->esi->getStructure($locationId, $structChar);
                        if ($esiStructure && isset($esiStructure['name'])) {
                            $name = $esiStructure['name'];
                            $solarSystemId = $esiStructure['solar_system_id'] ?? null;
                            break;
                        }
                    }
                }
            } elseif ($locationId >= 30000000 && $locationId < 33000000) {
                // Directly in a solar system (rare but valid).
                $system = SdeSolarSystem::find($locationId);
                if ($system) {
                    $name = $system->solar_system_name;
                    $solarSystemId = $locationId;
                }
            }

            $solarSystemName = null;
            if ($solarSystemId) {
                $sdeSystem = SdeSolarSystem::find($solarSystemId);
                if ($sdeSystem) {
                    $solarSystemName = $sdeSystem->solar_system_name;
                } else {
                    $esiSystem = $this->esi->getSystem($solarSystemId);
                    if ($esiSystem && isset($esiSystem['name'])) {
                        $solarSystemName = $esiSystem['name'];
                    }
                }
            }

            $locations[] = [
                'location_id' => $locationId,
                'name' => $name ?? "Location #{$locationId}",
                'solar_system_id' => $solarSystemId,
                'solar_system_name' => $solarSystemName,
            ];
        }

        usort($locations, function (array $a, array $b): int {
            $sysA = $a['solar_system_name'] !== null ? $a['solar_system_name'] : '~';
            $sysB = $b['solar_system_name'] !== null ? $b['solar_system_name'] : '~';

            return strcmp($sysA, $sysB) ?: strcmp($a['name'], $b['name']);
        });

        return $locations;
    }
}
