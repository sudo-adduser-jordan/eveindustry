<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discord_bot_settings', function (Blueprint $table) {
            $table->id();
            $table->text('bot_token')->nullable();
            $table->string('bot_name')->default('EVE Industrialist Bot');
            $table->string('bot_avatar_url', 512)->nullable();
            $table->string('welcome_channel_id', 30)->nullable();
            $table->text('welcome_message')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discord_bot_settings');
    }
};
