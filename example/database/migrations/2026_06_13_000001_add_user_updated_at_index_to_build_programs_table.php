<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_programs', function (Blueprint $table) {
            $table->index(['user_id', 'updated_at'], 'build_programs_user_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('build_programs', function (Blueprint $table) {
            $table->dropIndex('build_programs_user_updated_idx');
        });
    }
};
