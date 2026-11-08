<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturing_costs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('product_type_id');
            $table->unsignedInteger('blueprint_type_id');
            $table->unsignedInteger('region_id');
            $table->unsignedTinyInteger('me_level');
            $table->string('product_name');
            $table->string('blueprint_name');
            $table->unsignedInteger('portion_size')->default(1);
            $table->double('input_cost');
            $table->double('output_value');
            $table->double('base_profit');
            $table->double('profit_margin');
            $table->unsignedTinyInteger('sell_score')->default(0);
            $table->double('sell_score_avg_daily_volume')->default(0);
            $table->double('sell_score_avg_order_count')->default(0);
            $table->unsignedInteger('sell_score_active_days')->default(0);
            $table->unsignedInteger('sell_score_total_days')->default(0);
            $table->timestamps();

            $table->unique(['product_type_id', 'region_id', 'me_level'], 'mfg_cost_unique');
            $table->index('region_id');
            $table->index('me_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturing_costs');
    }
};
