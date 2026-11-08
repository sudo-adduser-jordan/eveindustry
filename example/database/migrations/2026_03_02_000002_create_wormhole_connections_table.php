<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wormhole_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signature_id')->constrained('wholemap_signatures')->cascadeOnDelete();
            $table->unsignedInteger('from_system_id');
            $table->string('from_system_name')->nullable();
            $table->unsignedInteger('to_system_id')->nullable();
            $table->string('to_system_name')->nullable();
            $table->string('wormhole_type', 10)->nullable();
            $table->enum('life', ['stable', 'critical'])->default('stable');
            $table->enum('mass', ['stable', 'destab', 'critical'])->default('stable');
            $table->timestamps();

            $table->index(['user_id', 'from_system_id']);
            $table->index('from_system_id');
            $table->index('to_system_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wormhole_connections');
    }
};
