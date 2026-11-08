<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reaction_plans', function (Blueprint $table) {
            $table->integer('price_region_id')->nullable()->after('solar_system_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reaction_plans', function (Blueprint $table) {
            $table->dropColumn('price_region_id');
        });
    }
};
