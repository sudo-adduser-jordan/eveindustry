<?php

namespace App\Http\Resources\V1\Planetary;

use App\Services\EsiService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A launchpad or storage facility with its fill level and contents.
 */
class PlanetaryStorageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $pin = $this->resource;
        $s = $pin['storage'];

        return [
            'kind' => $pin['category'] === 'launchpad' ? 'launchpad' : 'storage',
            /** Capacity in m³. */
            'capacity' => (float) $s['capacity'],
            'used_volume' => (float) $s['used_volume'],
            'usage_percent' => (float) $s['usage_percent'],
            /** @var list<array{type_id: int, name: string, icon_url: string, amount: int}> */
            'items' => array_map(fn (array $i) => [
                'type_id' => (int) $i['type_id'],
                'name' => (string) $i['name'],
                'icon_url' => EsiService::getTypeImageUrl((int) $i['type_id'], 'icon', 32),
                'amount' => (int) $i['amount'],
            ], $s['items'] ?? []),
        ];
    }
}
