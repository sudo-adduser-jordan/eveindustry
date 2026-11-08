<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corporation_build_programs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('corporation_id')->index();
            $table->unsignedBigInteger('created_by_user_id');
            $table->string('name');
            $table->string('program_type')->default('manufacturing')->comment('manufacturing or reaction');
            $table->unsignedBigInteger('t2_type_id')->nullable();
            $table->string('t2_type_name')->nullable();
            $table->json('steps');
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporation_build_programs');
    }
};
