<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One search hit for a blueprint, reaction formula or inventable T2 item.
 */
class TypeSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type_id' => (int) $this->resource['type_id'],
            'name' => (string) $this->resource['name'],
            'group_id' => isset($this->resource['group_id']) ? (int) $this->resource['group_id'] : null,
            'icon_url' => (string) $this->resource['icon_url'],
        ];
    }
}
