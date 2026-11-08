<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per region order sweep (market:fetch-region-orders). Records
     * how long each sweep took and whether every page arrived, so the Jita
     * cadence can be gated on measured duration and stale orders are only
     * removed after a complete sweep.
     */
    public function up(): void
    {
        Schema::create('market_order_sweeps', function (Blueprint $table) {
            $table->id();
            $table->uuid('sweep_id')->unique();
            $table->unsignedInteger('region_id');
            // running, completed, failed, abandoned
            $table->string('status', 16)->default('running');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedInteger('pages_total')->default(0);
            $table->unsignedInteger('pages_fetched')->default(0);
            $table->unsignedInteger('pages_not_modified')->default(0);
            $table->unsignedInteger('orders_seen')->default(0);
            $table->unsignedInteger('orders_deleted')->default(0);
            $table->unsignedInteger('types_aggregated')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['region_id', 'status', 'started_at'], 'market_order_sweeps_region_status_started_idx');
        });

        // Stale-order cleanup deletes a region's orders not touched since the
        // sweep started: WHERE region_id = ? AND updated_at < ?.
        Schema::table('market_orders', function (Blueprint $table) {
            $table->index(['region_id', 'updated_at'], 'market_orders_region_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('market_orders', function (Blueprint $table) {
            $table->dropIndex('market_orders_region_updated_idx');
        });

        Schema::dropIfExists('market_order_sweeps');
    }
};
