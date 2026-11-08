<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A character's hangars, or a corporation's (via a director), with what it can build.
 * From ProductionService::getCanProduceForUser().
 */
class CanProduceSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            /** `character` or `corporation`. */
            'type' => (string) $s['type'],
            /** The character, or the director the corporation is read through. */
            'character_id' => (int) $s['character_id'],
            'character_name' => (string) $s['character_name'],
            /** Characters only. */
            'portrait_url' => $s['portrait_url'] ?? null,
            'corporation_id' => isset($s['corporation_id']) ? (int) $s['corporation_id'] : null,
            'corporation_name' => $s['corporation_name'] ?? null,
            /** Corporations only. */
            'corporation_logo' => $s['corporation_logo'] ?? null,
            'blueprint_count' => (int) $s['blueprint_count'],
            'material_count' => (int) $s['material_count'],
            /** @var list<CanProduceItemResource> Most buildable runs first. */
            'buildable' => CanProduceItemResource::collection($s['buildable'])->resolve($request),
            /** @var list<CanProduceStockResource> By name. */
            'available_materials' => CanProduceStockResource::collection($s['available_materials'])->resolve($request),
        ];
    }
}
