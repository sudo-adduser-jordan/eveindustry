<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ess_tracked_systems', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('corporation_id');
            $table->integer('solar_system_id');
            $table->string('solar_system_name');
            $table->unsignedSmallInteger('cycle_minutes')->default(165);
            $table->timestamp('cycle_anchor_at');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['corporation_id', 'solar_system_id']);
            $table->index('corporation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ess_tracked_systems');
    }
};
