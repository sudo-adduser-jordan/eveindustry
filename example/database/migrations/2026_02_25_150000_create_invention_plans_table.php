<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invention_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('t2_type_id');
            $table->string('t2_type_name');
            $table->string('decryptor_key', 50)->default('none');
            $table->unsignedTinyInteger('t1_me')->default(0);
            $table->unsignedTinyInteger('t1_te')->default(0);
            $table->unsignedTinyInteger('encryption_skill')->default(0);
            $table->unsignedTinyInteger('science1_skill')->default(0);
            $table->unsignedTinyInteger('science2_skill')->default(0);
            $table->unsignedInteger('invention_runs')->default(1);
            $table->string('structure_type', 20)->nullable();
            $table->string('rig_me', 10)->nullable();
            $table->string('rig_te', 10)->nullable();
            $table->string('system_security', 10)->nullable();
            $table->decimal('facility_tax', 5, 2)->default(0);
            $table->unsignedBigInteger('solar_system_id')->nullable();
            $table->unsignedBigInteger('station_id')->nullable();
            $table->unsignedInteger('price_region_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invention_plans');
    }
};
