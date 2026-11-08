<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('type_materials', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('type_id');
            $table->unsignedInteger('material_type_id')->index();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['type_id', 'material_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('type_materials');
    }
};
