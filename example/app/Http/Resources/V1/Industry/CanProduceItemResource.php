<?php

namespace App\Http\Resources\V1\Industry;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item a source can build now, from ProductionService::calculateBuildableItems().
 */
class CanProduceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'blueprint_type_id' => (int) $i['blueprint_type_id'],
            'product_type_id' => (int) $i['product_type_id'],
            'product_name' => (string) $i['product_name'],
            'product_qty_per_run' => (int) $i['product_qty_per_run'],
            /** Material efficiency, percent. */
            'me' => (int) $i['me'],
            'te' => (int) $i['te'],
            /** Runs left on a blueprint copy; -1 for an original (unlimited). */
            'max_runs' => (int) $i['max_runs'],
            /** Runs the materials allow, capped by a copy's runs. */
            'buildable_runs' => (int) $i['buildable_runs'],
            'total_output' => (int) $i['total_output'],
            /** @var list<CanProduceMaterialResource> */
            'materials' => CanProduceMaterialResource::collection($i['materials'])->resolve($request),
        ];
    }
}
