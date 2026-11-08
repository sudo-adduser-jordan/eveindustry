<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A blueprint or reaction formula a build program needs.
 */
class ProgressBlueprintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $b = $this->resource;

        return [
            'type_id' => (int) $b['type_id'],
            'name' => (string) $b['name'],
            /** `manufacturing` or `reaction`. */
            'activity_type' => (string) $b['activity_type'],
            /** One is in the counted assets. */
            'owned' => (bool) $b['owned'],
        ];
    }
}
