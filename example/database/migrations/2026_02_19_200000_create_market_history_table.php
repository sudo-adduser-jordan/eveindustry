<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_history', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('region_id');
            $table->unsignedInteger('type_id');
            $table->date('date');
            $table->double('average');
            $table->double('highest');
            $table->double('lowest');
            $table->unsignedBigInteger('volume');
            $table->unsignedBigInteger('order_count');
            $table->timestamps();

            $table->unique(['region_id', 'type_id', 'date']);
            $table->index(['region_id', 'type_id']);
            $table->index('type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_history');
    }
};
