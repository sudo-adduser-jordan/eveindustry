<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sde_solar_systems', function (Blueprint $table) {
            $table->unsignedInteger('solar_system_id')->primary();
            $table->string('solar_system_name')->index();
            $table->unsignedInteger('constellation_id')->nullable()->index();
            $table->unsignedInteger('region_id')->nullable()->index();
            $table->float('security')->nullable();
            $table->string('security_class', 8)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sde_solar_systems');
    }
};
