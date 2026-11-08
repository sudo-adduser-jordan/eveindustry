<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('public_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('station_id')->nullable()->after('end_location_id');
            $table->unsignedInteger('system_id')->nullable()->after('station_id');
            $table->unsignedInteger('constellation_id')->nullable()->after('system_id');
            $table->timestamp('http_last_modified')->nullable()->after('volume');
        });

        Schema::table('public_contract_items', function (Blueprint $table) {
            $table->timestamp('http_last_modified')->nullable()->after('item_id');
        });
    }

    public function down(): void
    {
        Schema::table('public_contracts', function (Blueprint $table) {
            $table->dropColumn(['station_id', 'system_id', 'constellation_id', 'http_last_modified']);
        });

        Schema::table('public_contract_items', function (Blueprint $table) {
            $table->dropColumn('http_last_modified');
        });
    }
};
