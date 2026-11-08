<?php

namespace App\Services;

use App\Models\EveType;
use App\Models\MarketOrderSweep;
use App\Models\MarketPrice;
use Illuminate\Support\Facades\Schema;

/**
 * Tells a fresh install whether the game data it needs has arrived yet:
 * the SDE (item types), global prices and the first completed order sweep.
 * Read by GET /api/v1/health and by `app:install`.
 */
class SetupStatusService
{
    /**
     * @return array{ready: bool, steps: array{sde: bool, prices: bool, orders: bool}}
     */
    public function status(): array
    {
        try {
            $steps = [
                'sde' => Schema::hasTable('eve_types') && EveType::query()->exists(),
                'prices' => Schema::hasTable('market_prices') && MarketPrice::query()->exists(),
                'orders' => Schema::hasTable('market_order_sweeps')
                    && MarketOrderSweep::query()->where('status', MarketOrderSweep::STATUS_COMPLETED)->exists(),
            ];
        } catch (\Throwable) {
            $steps = ['sde' => false, 'prices' => false, 'orders' => false];
        }

        return ['ready' => ! in_array(false, $steps, true), 'steps' => $steps];
    }
}
