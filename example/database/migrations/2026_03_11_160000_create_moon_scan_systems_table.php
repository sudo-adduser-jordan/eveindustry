<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moon_scan_systems', function (Blueprint $table) {
            $table->id();
            $table->string('system_name');
            $table->unsignedBigInteger('corporation_id')->nullable();
            $table->unsignedBigInteger('region_id');
            $table->string('pricing_mode', 16)->default('raw'); // raw | compressed | refined
            $table->timestamp('last_scanned_at')->useCurrent();
            $table->timestamps();

            $table->unique(['system_name', 'corporation_id']);
            $table->index('corporation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moon_scan_systems');
    }
};
