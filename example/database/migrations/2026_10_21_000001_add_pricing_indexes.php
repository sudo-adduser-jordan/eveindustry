<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Indexes for the manufacturing browser and sell-price lookups.
     */
    public function up(): void
    {
        // ManufacturingBrowserService::browsePrecomputed() filters on
        // region_id + me_level and sorts by base_profit to page through the
        // list. The existing (region_id, me_level, product_type_id) index
        // cannot supply that order, so the database sorts every row first.
        Schema::table('manufacturing_costs', function (Blueprint $table) {
            $table->index(['region_id', 'me_level', 'base_profit'], 'mfg_cost_region_me_profit_idx');
        });

        // MarketTradingService's station-trading scan groups a region's
        // orders by type_id and takes MIN/MAX(price) per side
        // (is_buy_order). This index holds every column that scan needs, in
        // group order, so it can be answered from the index alone. The
        // existing (region_id, is_buy_order, type_id) index lacks price.
        Schema::table('market_orders', function (Blueprint $table) {
            $table->index(['region_id', 'type_id', 'is_buy_order', 'price'], 'market_orders_region_type_buy_price_idx');
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_costs', function (Blueprint $table) {
            $table->dropIndex('mfg_cost_region_me_profit_idx');
        });

        Schema::table('market_orders', function (Blueprint $table) {
            $table->dropIndex('market_orders_region_type_buy_price_idx');
        });
    }
};
