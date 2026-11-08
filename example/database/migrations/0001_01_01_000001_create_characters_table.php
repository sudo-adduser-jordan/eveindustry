<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->unsignedBigInteger('character_id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('corporation_id');
            $table->string('corporation_name')->nullable();
            $table->unsignedBigInteger('alliance_id')->nullable();
            $table->string('alliance_name')->nullable();
            $table->boolean('is_main')->default(false);
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('corporation_id');
            $table->index('alliance_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
