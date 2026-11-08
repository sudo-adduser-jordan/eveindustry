<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eve_types', function (Blueprint $table) {
            $table->unsignedSmallInteger('meta_group_id')->nullable()->index()->after('graphic_id');
        });
    }

    public function down(): void
    {
        Schema::table('eve_types', function (Blueprint $table) {
            $table->dropColumn('meta_group_id');
        });
    }
};
