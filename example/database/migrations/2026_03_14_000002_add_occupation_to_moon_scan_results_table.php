<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moon_scan_results', function (Blueprint $table) {
            $table->boolean('occupied')->default(false)->after('scanned_at');
            $table->unsignedBigInteger('occupying_corporation_id')->nullable()->after('occupied');
            $table->string('occupying_corporation_name')->nullable()->after('occupying_corporation_id');
        });
    }

    public function down(): void
    {
        Schema::table('moon_scan_results', function (Blueprint $table) {
            $table->dropColumn(['occupied', 'occupying_corporation_id', 'occupying_corporation_name']);
        });
    }
};
