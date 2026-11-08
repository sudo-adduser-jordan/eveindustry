<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inv_groups', function (Blueprint $table) {
            $table->unsignedInteger('group_id')->primary();
            $table->string('name')->index();
            $table->unsignedInteger('category_id')->nullable()->index();
            $table->boolean('published')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inv_groups');
    }
};
