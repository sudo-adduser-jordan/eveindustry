<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wholemap_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('signature_id', 7)->nullable();
            $table->unsignedInteger('system_id');
            $table->string('system_name')->nullable();
            $table->enum('type', ['unknown', 'combat', 'data', 'relic', 'ore', 'gas', 'wormhole', 'ghost'])->default('unknown');
            $table->string('name', 100)->nullable();
            $table->string('bookmark', 100)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'system_id']);
            $table->index('system_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wholemap_signatures');
    }
};
