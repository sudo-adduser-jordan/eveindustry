<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->unsignedInteger('region_id')->index();
            $table->unsignedInteger('type_id')->index();
            $table->boolean('is_buy_order')->default(false);
            $table->double('price');
            $table->unsignedBigInteger('volume_remain');
            $table->unsignedBigInteger('volume_total');
            $table->unsignedBigInteger('min_volume')->default(1);
            $table->unsignedBigInteger('location_id')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->string('range', 20)->nullable();
            $table->timestamp('issued')->nullable();
            $table->string('batch_id', 36)->nullable()->index();
            $table->timestamps();

            $table->index(['region_id', 'type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_orders');
    }
};
