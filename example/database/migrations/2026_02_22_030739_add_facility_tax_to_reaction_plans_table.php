<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reaction_plans', function (Blueprint $table) {
            $table->decimal('facility_tax', 5, 2)->default(0)->after('security_space');
        });
    }

    public function down(): void
    {
        Schema::table('reaction_plans', function (Blueprint $table) {
            $table->dropColumn('facility_tax');
        });
    }
};
