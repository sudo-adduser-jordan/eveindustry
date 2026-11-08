<?php

namespace App\Http\Resources\V1\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One station-trading opportunity from MarketTradingService::scanStationTrading().
 */
class StationTradingOpportunityResource extends JsonResource
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
            /** Highest buy order (ISK). */
            'best_buy' => (float) $row['best_buy'],
            /** Lowest sell order (ISK). */
            'best_sell' => (float) $row['best_sell'],
            'gross_margin_pct' => (float) $row['gross_margin_pct'],
            /** After broker fees on both orders and sales tax (ISK). */
            'net_profit_per_unit' => (float) $row['net_profit_per_unit'],
            'net_margin_pct' => (float) $row['net_margin_pct'],
            'roi_pct' => (float) $row['roi_pct'],
            /** Lowest sell price that covers all fees (ISK). */
            'break_even' => (float) $row['break_even'],
            /** Average daily volume over the last 30 history rows; 0 when there is no history. */
            'daily_volume' => (int) $row['daily_volume'],
            'daily_isk_profit' => (float) $row['daily_isk_profit'],
            'buy_order_count' => (int) $row['buy_order_count'],
            'sell_order_count' => (int) $row['sell_order_count'],
        ];
    }
}
