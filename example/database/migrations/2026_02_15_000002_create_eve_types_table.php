<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eve_types', function (Blueprint $table) {
            $table->unsignedInteger('type_id')->primary();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->unsignedInteger('group_id')->nullable()->index();
            $table->unsignedInteger('market_group_id')->nullable()->index();
            $table->double('volume')->nullable();
            $table->double('packaged_volume')->nullable();
            $table->double('mass')->nullable();
            $table->double('capacity')->nullable();
            $table->unsignedInteger('portion_size')->nullable();
            $table->unsignedInteger('icon_id')->nullable();
            $table->unsignedInteger('graphic_id')->nullable();
            $table->boolean('published')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eve_types');
    }
};
