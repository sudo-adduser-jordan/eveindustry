<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An input a reaction in a build program consumes.
 */
class ProgressReactionInputResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'type_id' => (int) $i['type_id'],
            'name' => (string) $i['name'],
            'required_qty' => (int) $i['required_qty'],
            'available' => (int) $i['available'],
        ];
    }
}
