<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('moon_scan_results', function (Blueprint $table) {
            $table->id();
            $table->string('moon_name');
            $table->string('planet_name')->nullable();
            $table->string('system_name')->nullable();
            $table->string('grade', 2)->default('D');
            $table->json('ores');
            $table->unsignedBigInteger('total_value')->default(0);
            $table->string('pricing_mode', 16)->default('raw'); // raw | compressed | refined
            $table->unsignedBigInteger('region_id');
            $table->timestamp('scanned_at')->useCurrent();
            $table->timestamps();

            $table->index(['system_name', 'scanned_at']);
            $table->index('scanned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moon_scan_results');
    }
};
