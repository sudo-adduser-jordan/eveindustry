<?php

namespace App\Http\Resources\V1\Reference;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A corporation found through ESI search.
 */
class CorporationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'corporation_id' => (int) $this->resource['id'],
            'name' => (string) $this->resource['name'],
            'ticker' => (string) $this->resource['ticker'],
        ];
    }
}
