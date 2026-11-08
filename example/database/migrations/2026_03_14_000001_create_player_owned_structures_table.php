<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_owned_structures', function (Blueprint $table) {
            $table->unsignedBigInteger('structure_id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->unsignedInteger('solar_system_id')->nullable()->index();
            $table->unsignedInteger('type_id')->nullable();
            $table->double('position_x')->nullable();
            $table->double('position_y')->nullable();
            $table->double('position_z')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_owned_structures');
    }
};
