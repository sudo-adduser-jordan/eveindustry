<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_groups', function (Blueprint $table) {
            $table->unsignedInteger('market_group_id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('parent_group_id')->nullable()->index();
            $table->unsignedInteger('icon_id')->nullable();
            $table->boolean('has_types')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_groups');
    }
};
