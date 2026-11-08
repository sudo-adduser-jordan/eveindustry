<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moon_scan_results', function (Blueprint $table) {
            $table->unsignedBigInteger('corporation_id')->nullable()->after('region_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('moon_scan_results', function (Blueprint $table) {
            $table->dropColumn('corporation_id');
        });
    }
};
