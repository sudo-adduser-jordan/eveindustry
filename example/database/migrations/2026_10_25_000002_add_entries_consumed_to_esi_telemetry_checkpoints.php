<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entries consumed from the current buffer lifetime; compared with the buffer's own
        // add/remove counters to detect entries the cap evicted before they were read.
        Schema::table('esi_telemetry_checkpoints', function (Blueprint $table) {
            $table->unsignedBigInteger('entries_consumed')->default(0)->after('last_stream_id');
        });
    }

    public function down(): void
    {
        Schema::table('esi_telemetry_checkpoints', function (Blueprint $table) {
            $table->dropColumn('entries_consumed');
        });
    }
};
