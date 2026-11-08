<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sde_stargates', function (Blueprint $table) {
            $table->unsignedInteger('stargate_id')->primary();
            $table->unsignedInteger('solar_system_id')->nullable()->index();
            $table->unsignedInteger('destination_stargate_id')->nullable()->index();
            $table->unsignedInteger('type_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sde_stargates');
    }
};
