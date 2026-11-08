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
            $table->unsignedInteger('solar_system_id')->nullable()->after('station_id');
        });
    }

    public function down(): void
    {
        Schema::table('reaction_plans', function (Blueprint $table) {
            $table->dropColumn('solar_system_id');
        });
    }
};
