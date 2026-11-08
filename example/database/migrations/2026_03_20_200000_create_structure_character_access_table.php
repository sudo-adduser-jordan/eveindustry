<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structure_character_access', function (Blueprint $table) {
            $table->unsignedBigInteger('structure_id');
            $table->unsignedBigInteger('character_id');
            $table->timestamp('access_denied_at')->nullable();
            $table->timestamps();

            $table->primary(['structure_id', 'character_id']);
            $table->index('character_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structure_character_access');
    }
};
