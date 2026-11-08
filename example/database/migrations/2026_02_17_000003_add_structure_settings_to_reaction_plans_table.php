<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reaction_plans', function (Blueprint $table) {
            $table->string('structure_type')->default('athanor')->after('rig_bonus');
            $table->string('rig_tier')->default('none')->after('structure_type');
            $table->string('security_space')->default('lowsec')->after('rig_tier');
        });
    }

    public function down(): void
    {
        Schema::table('reaction_plans', function (Blueprint $table) {
            $table->dropColumn(['structure_type', 'rig_tier', 'security_space']);
        });
    }
};
