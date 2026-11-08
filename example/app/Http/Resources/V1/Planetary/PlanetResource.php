<?php

namespace App\Http\Resources\V1\Planetary;

use App\Services\EsiService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One colony: pin counts, extractors, factories, launchpad and storage
 * usage, and its alerts (from PlanetaryService::analyzePlanet).
 */
class PlanetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $summary = $p['summary'];

        return [
            'planet_id' => (int) $p['planet_id'],
            'planet_name' => (string) $p['planet_name'],
            /** barren, temperate, ice, gas, lava, oceanic, plasma, storm */
            'planet_type' => (string) $p['planet_type'],
            'upgrade_level' => (int) $p['upgrade_level'],
            'num_pins' => (int) $p['num_pins'],
            'extractor_count' => (int) $summary['extractor_count'],
            'factory_count' => (int) $summary['factory_count'],
            'storage_count' => (int) $summary['storage_count'],
            'launchpad_count' => (int) $summary['launchpad_count'],
            'route_count' => count($p['routes'] ?? []),
            /** @var list<array{type: string, severity: string, message: string}> */
            'alerts' => array_map(fn (array $a) => [
                'type' => (string) $a['type'],
                'severity' => (string) $a['severity'],
                'message' => (string) $a['message'],
            ], $p['alerts'] ?? []),
            /** @var list<array{type_id: int, name: string, icon_url: string, per_hour: float}> Extractor output of the colony. */
            'outputs' => array_map(fn (array $o) => [
                'type_id' => (int) $o['type_id'],
                'name' => (string) $o['name'],
                'icon_url' => EsiService::getTypeImageUrl((int) $o['type_id'], 'icon', 32),
                'per_hour' => (float) $o['per_hour'],
            ], $p['outputs'] ?? []),
            /** @var list<PlanetaryExtractorResource> */
            'extractors' => array_values(array_map(
                fn (array $pin) => (new PlanetaryExtractorResource($pin['extractor']))->resolve($request),
                array_filter($p['pins'] ?? [], fn (array $pin) => isset($pin['extractor'])),
            )),
            /** @var list<array{schematic_id: int, schematic_name: string, cycle_time: int}> */
            'factories' => array_values(array_map(fn (array $pin) => [
                'schematic_id' => (int) $pin['factory']['schematic_id'],
                'schematic_name' => (string) $pin['factory']['schematic_name'],
                'cycle_time' => (int) $pin['factory']['cycle_time'],
            ], array_filter($p['pins'] ?? [], fn (array $pin) => isset($pin['factory'])))),
            /** @var list<PlanetaryStorageResource> */
            'storages' => array_values(array_map(
                fn (array $pin) => (new PlanetaryStorageResource($pin))->resolve($request),
                array_filter($p['pins'] ?? [], fn (array $pin) => isset($pin['storage'])),
            )),
        ];
    }
}
