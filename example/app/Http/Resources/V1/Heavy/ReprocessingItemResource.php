<?php

namespace App\Http\Resources\V1\Heavy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An item stack worth more reprocessed than sold. Values are in ISK at
 * global market prices.
 */
class ReprocessingItemResource extends JsonResource
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
            'quantity' => (int) $i['quantity'],
            'market_value' => (float) $i['market_value'],
            'reprocess_value' => (float) $i['reprocess_value'],
            'profit' => (float) $i['profit'],
            /** Profit as a percentage of the market value. */
            'profit_pct' => (float) $i['profit_pct'],
            'unit_market_price' => (float) $i['unit_market_price'],
            /** @var list<array{type_id: int, name: string, quantity: int, unit_price: float, total_value: float}> What the stack reprocesses into at the character's yield. */
            'materials' => array_map(fn (array $m) => [
                'type_id' => (int) $m['type_id'],
                'name' => (string) $m['name'],
                'quantity' => (int) $m['quantity'],
                'unit_price' => (float) $m['unit_price'],
                'total_value' => (float) $m['total_value'],
            ], $i['materials'] ?? []),
            'icon_url' => (string) $i['icon_url'],
        ];
    }
}
