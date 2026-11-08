<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * prices_as_of: snapshot time of the market aggregates the row's prices
     * came from (null when no completed sweep existed for the region).
     * updated_at stays the time the row was computed.
     */
    public function up(): void
    {
        Schema::table('manufacturing_costs', function (Blueprint $table) {
            $table->timestamp('prices_as_of')->nullable()->after('sell_score_total_days');
            // Stale-row cleanup: WHERE region_id = ? AND updated_at < ?.
            $table->index(['region_id', 'updated_at'], 'mfg_cost_region_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('manufacturing_costs', function (Blueprint $table) {
            $table->dropIndex('mfg_cost_region_updated_idx');
            $table->dropColumn('prices_as_of');
        });
    }
};
