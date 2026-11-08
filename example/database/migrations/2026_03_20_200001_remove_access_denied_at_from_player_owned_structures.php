<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_owned_structures', function (Blueprint $table) {
            $table->dropColumn('access_denied_at');
        });
    }

    public function down(): void
    {
        Schema::table('player_owned_structures', function (Blueprint $table) {
            $table->timestamp('access_denied_at')->nullable()->after('structure_id');
        });
    }
};
