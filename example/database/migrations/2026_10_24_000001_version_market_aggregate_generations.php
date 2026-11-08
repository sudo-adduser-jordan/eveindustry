<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('market_aggregates', function (Blueprint $table) {
            $table->dropUnique('market_aggregates_region_type_unique');
            $table->unique(['region_id', 'sweep_id', 'type_id'], 'market_aggregates_region_sweep_type_unique');
            $table->index(['region_id', 'sweep_id'], 'market_aggregates_region_sweep_idx');
        });

        Schema::table('market_order_sweeps', function (Blueprint $table) {
            $table->unsignedBigInteger('generation')->default(0)->after('region_id');
        });

        Schema::create('market_region_states', function (Blueprint $table) {
            $table->unsignedInteger('region_id')->primary();
            $table->unsignedBigInteger('latest_generation')->default(0);
            $table->uuid('latest_sweep_id')->nullable();
            $table->timestamp('latest_started_at')->nullable();
            $table->unsignedBigInteger('active_generation')->default(0);
            $table->uuid('active_sweep_id')->nullable();
            $table->timestamp('active_snapshot_at')->nullable();
            $table->timestamps();

            $table->index('active_sweep_id', 'market_region_states_active_sweep_idx');
        });

        $generations = [];
        $latest = [];
        $active = [];

        DB::table('market_order_sweeps')
            ->orderBy('region_id')
            ->orderBy('started_at')
            ->orderBy('id')
            ->get(['id', 'region_id', 'sweep_id', 'status', 'started_at'])
            ->each(function ($sweep) use (&$generations, &$latest, &$active) {
                $regionId = (int) $sweep->region_id;
                $generation = ($generations[$regionId] ?? 0) + 1;
                $generations[$regionId] = $generation;

                DB::table('market_order_sweeps')->where('id', $sweep->id)->update(['generation' => $generation]);

                $latest[$regionId] = [
                    'generation' => $generation,
                    'sweep_id' => $sweep->sweep_id,
                    'started_at' => $sweep->started_at,
                ];

                if ($sweep->status === 'completed') {
                    $active[$regionId] = $latest[$regionId];
                }
            });

        foreach ($latest as $regionId => $latestSweep) {
            $activeSweep = $active[$regionId] ?? null;
            $now = now();

            DB::table('market_region_states')->insert([
                'region_id' => $regionId,
                'latest_generation' => $latestSweep['generation'],
                'latest_sweep_id' => $latestSweep['sweep_id'],
                'latest_started_at' => $latestSweep['started_at'],
                'active_generation' => $activeSweep['generation'] ?? 0,
                'active_sweep_id' => $activeSweep['sweep_id'] ?? null,
                'active_snapshot_at' => $activeSweep['started_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // The old schema cannot retain multiple generations. Keep the newest
        // row for each region/type before restoring its uniqueness constraint.
        DB::table('market_aggregates')
            ->orderBy('region_id')
            ->orderBy('type_id')
            ->orderByDesc('snapshot_at')
            ->orderByDesc('id')
            ->get(['id', 'region_id', 'type_id'])
            ->groupBy(fn ($row) => $row->region_id.':'.$row->type_id)
            ->each(fn ($rows) => DB::table('market_aggregates')->whereIn('id', $rows->skip(1)->pluck('id'))->delete());

        Schema::table('market_aggregates', function (Blueprint $table) {
            $table->dropUnique('market_aggregates_region_sweep_type_unique');
            $table->dropIndex('market_aggregates_region_sweep_idx');
            $table->unique(['region_id', 'type_id'], 'market_aggregates_region_type_unique');
        });

        Schema::dropIfExists('market_region_states');

        Schema::table('market_order_sweeps', function (Blueprint $table) {
            $table->dropColumn('generation');
        });
    }
};
