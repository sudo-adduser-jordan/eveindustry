<?php

namespace App\Http\Resources\V1\Reference;

use App\Services\EsiService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inventory type from the SDE (or ESI /universe/types when the SDE has no row).
 */
class TypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $type = $this->resource;
        $typeId = (int) $type['type_id'];

        return [
            'type_id' => $typeId,
            'name' => (string) ($type['name'] ?? ''),
            'description' => (string) ($type['description'] ?? ''),
            'group_id' => self::intOrNull($type['group_id'] ?? null),
            'market_group_id' => self::intOrNull($type['market_group_id'] ?? null),
            /** Volume in m3. */
            'volume' => self::floatOrNull($type['volume'] ?? null),
            /** Packaged volume in m3. */
            'packaged_volume' => self::floatOrNull($type['packaged_volume'] ?? null),
            /** Mass in kg. */
            'mass' => self::floatOrNull($type['mass'] ?? null),
            /** Cargo capacity in m3. */
            'capacity' => self::floatOrNull($type['capacity'] ?? null),
            'portion_size' => self::intOrNull($type['portion_size'] ?? null),
            'icon_id' => self::intOrNull($type['icon_id'] ?? null),
            'graphic_id' => self::intOrNull($type['graphic_id'] ?? null),
            'published' => (bool) ($type['published'] ?? false),
            'icon_url' => EsiService::getTypeImageUrl($typeId, 'icon', 64),
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
