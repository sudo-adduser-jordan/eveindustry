<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('station_configs', function (Blueprint $table) {
            $table->string('activity_type')->default('manufacturing')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('station_configs', function (Blueprint $table) {
            $table->dropColumn('activity_type');
        });
    }
};
