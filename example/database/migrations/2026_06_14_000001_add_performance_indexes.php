<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add composite indexes that match the application's hot query paths to
     * reduce CPU spent on table scans and speed up market/industry lookups.
     */
    public function up(): void
    {
        // MarketService::getSellPrices() filters by region_id + is_buy_order
        // (equality) then type_id IN (...). Lead with the equality columns.
        Schema::table('market_orders', function (Blueprint $table) {
            $table->index(['region_id', 'is_buy_order', 'type_id'], 'market_orders_region_buy_type_idx');
        });

        // FetchRegionHistory cleanup deletes by region_id + date range; no
        // existing index leads with (region_id, date).
        Schema::table('market_history', function (Blueprint $table) {
            $table->index(['region_id', 'date'], 'market_history_region_date_idx');
        });

        // ManufacturingBrowserService filters region_id + me_level (equality)
        // then product_type_id IN (...) and orders by base_profit.
        Schema::table('manufacturing_costs', function (Blueprint $table) {
            $table->index(['region_id', 'me_level', 'product_type_id'], 'mfg_cost_region_me_product_idx');
        });

        // Per-user active character lookups (whereHas / where user_id + is_esi_active).
        Schema::table('characters', function (Blueprint $table) {
            $table->index(['user_id', 'is_esi_active'], 'characters_user_esi_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', function (Blueprint $table) {
            $table->dropIndex('market_orders_region_buy_type_idx');
        });

        Schema::table('market_history', function (Blueprint $table) {
            $table->dropIndex('market_history_region_date_idx');
        });

        Schema::table('manufacturing_costs', function (Blueprint $table) {
            $table->dropIndex('mfg_cost_region_me_product_idx');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->dropIndex('characters_user_esi_active_idx');
        });
    }
};
