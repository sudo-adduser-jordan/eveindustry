<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('build_programs', function (Blueprint $table) {
            $table->string('program_type')->default('manufacturing')->after('name');
            $table->integer('t2_type_id')->nullable()->change();
            $table->string('t2_type_name')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('build_programs', function (Blueprint $table) {
            $table->dropColumn('program_type');
            $table->integer('t2_type_id')->nullable(false)->change();
            $table->string('t2_type_name')->nullable(false)->change();
        });
    }
};
