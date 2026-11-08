<?php

namespace App\Http\Resources\V1\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One day of regional market history (market_history table).
 */
class MarketHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            /** YYYY-MM-DD */
            'date' => (string) $row['date'],
            'average' => (float) $row['average'],
            'highest' => (float) $row['highest'],
            'lowest' => (float) $row['lowest'],
            'volume' => (int) $row['volume'],
            'order_count' => (int) $row['order_count'],
        ];
    }
}
