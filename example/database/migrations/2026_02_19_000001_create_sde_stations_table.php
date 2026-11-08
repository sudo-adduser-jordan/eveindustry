<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sde_stations', function (Blueprint $table) {
            $table->unsignedBigInteger('station_id')->primary();
            $table->string('station_name')->index();
            $table->unsignedInteger('solar_system_id')->nullable()->index();
            $table->unsignedInteger('constellation_id')->nullable()->index();
            $table->unsignedInteger('region_id')->nullable()->index();
            $table->unsignedInteger('station_type_id')->nullable();
            $table->unsignedInteger('corporation_id')->nullable();
            $table->float('reprocessing_efficiency')->nullable();
            $table->float('security')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sde_stations');
    }
};
