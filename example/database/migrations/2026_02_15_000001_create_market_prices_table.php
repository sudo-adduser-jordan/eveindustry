<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_prices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('type_id');
            $table->integer('region_id')->default(10000002);
            $table->decimal('average_price', 20, 2)->nullable();
            $table->decimal('adjusted_price', 20, 2)->nullable();
            $table->decimal('sell_min', 20, 2)->nullable();
            $table->decimal('buy_max', 20, 2)->nullable();
            $table->bigInteger('sell_volume')->nullable();
            $table->bigInteger('buy_volume')->nullable();
            $table->bigInteger('daily_volume')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->index('type_id');
            $table->index('region_id');
            $table->unique(['type_id', 'region_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_prices');
    }
};
