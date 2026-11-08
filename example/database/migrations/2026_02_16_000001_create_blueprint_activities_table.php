<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blueprint_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('blueprint_type_id')->index();
            $table->unsignedTinyInteger('activity_id')->index(); // 1=manufacturing, 5=copying, 8=invention, 9=reaction
            $table->unsignedInteger('time')->default(0);
            $table->json('materials')->nullable();
            $table->json('products')->nullable();
            $table->timestamps();

            $table->unique(['blueprint_type_id', 'activity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blueprint_activities');
    }
};
