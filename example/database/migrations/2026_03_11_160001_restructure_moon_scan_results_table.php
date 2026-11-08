<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Migrate existing data into moon_scan_systems before restructuring
        if (Schema::hasTable('moon_scan_results') && Schema::hasColumn('moon_scan_results', 'system_name')) {
            $rows = \Illuminate\Support\Facades\DB::table('moon_scan_results')
                ->select('system_name', 'corporation_id', 'region_id', 'pricing_mode', 'scanned_at')
                ->whereNotNull('system_name')
                ->orderBy('scanned_at')
                ->get();

            foreach ($rows as $row) {
                \Illuminate\Support\Facades\DB::table('moon_scan_systems')->updateOrInsert(
                    ['system_name' => $row->system_name, 'corporation_id' => $row->corporation_id],
                    [
                        'region_id' => $row->region_id,
                        'pricing_mode' => $row->pricing_mode,
                        'last_scanned_at' => $row->scanned_at,
                        'created_at' => $row->scanned_at,
                        'updated_at' => $row->scanned_at,
                    ]
                );
            }
        }

        Schema::table('moon_scan_results', function (Blueprint $table) {
            // Add system_id FK (nullable for migration safety)
            $table->unsignedBigInteger('system_id')->nullable()->after('id');
            $table->foreign('system_id')->references('id')->on('moon_scan_systems')->cascadeOnDelete();
        });

        // Back-fill system_id for existing rows
        if (Schema::hasColumn('moon_scan_results', 'system_name')) {
            $systems = \Illuminate\Support\Facades\DB::table('moon_scan_systems')
                ->select('id', 'system_name', 'corporation_id')
                ->get();

            foreach ($systems as $system) {
                \Illuminate\Support\Facades\DB::table('moon_scan_results')
                    ->where('system_name', $system->system_name)
                    ->where('corporation_id', $system->corporation_id)
                    ->update(['system_id' => $system->id]);
            }
        }

        Schema::table('moon_scan_results', function (Blueprint $table) {
            // Remove columns now owned by moon_scan_systems.
            // Drop indexes before dropping columns (required by SQLite).
            // Use explicit names from the original migrations:
            //   - 2026_03_11_145231 created index on [system_name, scanned_at]
            //   - 2026_03_11_151834 created index on corporation_id via ->index()
            if (Schema::hasIndex('moon_scan_results', 'moon_scan_results_system_name_scanned_at_index')) {
                $table->dropIndex('moon_scan_results_system_name_scanned_at_index');
            }
            if (Schema::hasIndex('moon_scan_results', 'moon_scan_results_corporation_id_index')) {
                $table->dropIndex('moon_scan_results_corporation_id_index');
            }
            $table->dropColumn(['system_name', 'region_id', 'pricing_mode', 'corporation_id']);

            // Unique constraint: one row per (system, moon)
            $table->unique(['system_id', 'moon_name']);
        });
    }

    public function down(): void
    {
        Schema::table('moon_scan_results', function (Blueprint $table) {
            $table->dropUnique(['system_id', 'moon_name']);
            $table->dropForeign(['system_id']);
            $table->dropColumn('system_id');

            $table->string('system_name')->nullable()->after('planet_name');
            $table->unsignedBigInteger('region_id')->default(10000002)->after('total_value');
            $table->string('pricing_mode', 16)->default('raw')->after('region_id');
            $table->unsignedBigInteger('corporation_id')->nullable()->after('region_id');

            $table->index(['system_name', 'scanned_at']);
        });
    }
};
