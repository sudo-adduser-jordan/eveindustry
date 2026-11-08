<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corporation_build_programs', function (Blueprint $table) {
            $table->index(['corporation_id', 'updated_at'], 'corp_build_programs_corp_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('corporation_build_programs', function (Blueprint $table) {
            $table->dropIndex('corp_build_programs_corp_updated_idx');
        });
    }
};
