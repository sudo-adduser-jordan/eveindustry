<?php

namespace App\Http\Resources\V1\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One region-arbitrage opportunity from MarketTradingService::scanRegionArbitrage().
 */
class ArbitrageOpportunityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;

        return [
            'type_id' => (int) $row['type_id'],
            'type_name' => (string) $row['type_name'],
            /** Packaged volume, else volume (m3 per unit). */
            'volume_m3' => (float) $row['volume_m3'],
            /** Lowest sell order in the buy region (ISK). */
            'buy_price' => (float) $row['buy_price'],
            /** Highest buy order in the sell region (ISK). */
            'sell_price' => (float) $row['sell_price'],
            /** After sales tax and transport (ISK). */
            'net_profit_per_unit' => (float) $row['net_profit_per_unit'],
            'net_margin_pct' => (float) $row['net_margin_pct'],
            'roi_pct' => (float) $row['roi_pct'],
            /** Average daily volume in the sell region; 0 when there is no history. */
            'daily_volume' => (int) $row['daily_volume'],
            'daily_isk_profit' => (float) $row['daily_isk_profit'],
            /** Net profit per m3 hauled; null when the volume is 0. */
            'cargo_value_m3' => $row['cargo_value_m3'] !== null ? (float) $row['cargo_value_m3'] : null,
        ];
    }
}
