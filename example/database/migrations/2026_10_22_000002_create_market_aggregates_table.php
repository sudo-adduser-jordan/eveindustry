<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-type, per-region summary of the order book, rebuilt from
     * market_orders each time a region sweep completes. One row per
     * (region, type): the latest completed snapshot. Pricing reads this
     * instead of scanning raw orders.
     */
    public function up(): void
    {
        Schema::create('market_aggregates', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('region_id');
            $table->unsignedInteger('type_id');
            $table->uuid('sweep_id');
            $table->timestamp('snapshot_at');
            $table->double('best_bid')->nullable();
            $table->double('best_ask')->nullable();
            // Volume-weighted average of the cheapest 5% of sell volume,
            // the same figure MarketService::getSellPrices() returns.
            $table->double('sell_price_p5')->nullable();
            $table->unsignedBigInteger('sell_volume')->default(0);
            $table->unsignedBigInteger('buy_volume')->default(0);
            $table->unsignedInteger('sell_orders')->default(0);
            $table->unsignedInteger('buy_orders')->default(0);
            $table->timestamps();

            $table->unique(['region_id', 'type_id'], 'market_aggregates_region_type_unique');
            $table->index(['region_id', 'snapshot_at'], 'market_aggregates_region_snapshot_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_aggregates');
    }
};
