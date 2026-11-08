<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporation_build_program_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('corporation_id')->unique();
            $table->string('corporation_name')->nullable();
            $table->unsignedBigInteger('character_id')->comment('Director/CEO character providing asset access');
            $table->unsignedBigInteger('enabled_by_user_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('character_id')
                ->references('character_id')
                ->on('characters')
                ->onDelete('cascade');

            $table->foreign('enabled_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_build_program_configs');
    }
};
