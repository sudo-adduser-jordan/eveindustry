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
        Schema::table('station_configs', function (Blueprint $table) {
            $table->unsignedInteger('solar_system_id')->nullable()->after('system_security');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('solar_system_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('station_configs', function (Blueprint $table) {
            $table->dropColumn(['solar_system_id', 'tax_rate']);
        });
    }
};
