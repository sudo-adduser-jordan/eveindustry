<?php

namespace App\Http\Resources\V1\Planetary;

use App\Services\EsiService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Planetary interaction across the user's characters: totals, combined
 * production rates, alerts, factory capacity and every colony.
 */
class PlanetaryDashboardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{characters: list<array<string, mixed>>, summary: array<string, int>, outputs: list<array<string, mixed>>, alerts: list<array<string, mixed>>, capacity: list<array<string, mixed>>} $r */
        $r = $this->resource;

        return [
            'summary' => [
                'characters' => (int) $r['summary']['total_characters'],
                'planets' => (int) $r['summary']['total_planets'],
                'extractors' => (int) $r['summary']['total_extractors'],
                'factories' => (int) $r['summary']['total_factories'],
            ],
            /** @var list<array{type_id: int, name: string, icon_url: string, per_hour: float, planets: int}> Output per hour across all colonies, highest first. */
            'outputs' => array_map(fn (array $o) => [
                'type_id' => (int) $o['type_id'],
                'name' => (string) $o['name'],
                'icon_url' => EsiService::getTypeImageUrl((int) $o['type_id'], 'icon', 32),
                'per_hour' => (float) $o['total_per_hour'],
                'planets' => (int) $o['planets'],
            ], $r['outputs']),
            /** @var list<array{type: string, severity: string, message: string, character_name: string, planet_name: string}> */
            'alerts' => array_map(fn (array $a) => [
                'type' => (string) $a['type'],
                'severity' => (string) $a['severity'],
                'message' => (string) $a['message'],
                'character_name' => (string) $a['character_name'],
                'planet_name' => (string) $a['planet_name'],
            ], $r['alerts']),
            /** @var list<array{character_name: string, planet_name: string, planet_type: string, extractor_count: int, factory_count: int, status: 'balanced'|'under_capacity'|'over_capacity'|'idle_factories'}> */
            'capacity' => array_map(fn (array $c) => [
                'character_name' => (string) $c['character_name'],
                'planet_name' => (string) $c['planet_name'],
                'planet_type' => (string) $c['planet_type'],
                'extractor_count' => (int) $c['extractor_count'],
                'factory_count' => (int) $c['factory_count'],
                'status' => (string) $c['status'],
            ], $r['capacity']),
            /** @var list<PlanetaryCharacterResource> */
            'characters' => PlanetaryCharacterResource::collection($r['characters'])->resolve($request),
        ];
    }
}
