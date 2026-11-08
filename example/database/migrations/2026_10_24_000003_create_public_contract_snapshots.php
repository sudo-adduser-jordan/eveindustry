<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_contract_generations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('contract_count')->default(0);
            $table->unsignedBigInteger('item_count')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
        });

        Schema::create('public_contract_snapshot_state', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('active_generation_id')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->foreign('active_generation_id')->references('id')->on('public_contract_generations')->restrictOnDelete();
        });

        Schema::create('public_contract_snapshot_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('generation_id');
            $table->unsignedBigInteger('contract_id');
            $table->unsignedInteger('region_id')->index();
            $table->string('type', 30)->index();
            $table->string('title')->nullable();
            $table->boolean('for_corporation')->default(false);
            $table->unsignedBigInteger('issuer_corporation_id')->nullable();
            $table->unsignedBigInteger('issuer_id')->nullable();
            $table->timestamp('date_issued')->nullable();
            $table->timestamp('date_expired')->nullable();
            $table->unsignedInteger('days_to_complete')->default(0);
            $table->unsignedBigInteger('start_location_id')->nullable();
            $table->unsignedBigInteger('end_location_id')->nullable();
            $table->unsignedBigInteger('station_id')->nullable();
            $table->unsignedInteger('system_id')->nullable();
            $table->unsignedInteger('constellation_id')->nullable();
            $table->double('price')->default(0);
            $table->double('buyout')->nullable();
            $table->double('reward')->nullable();
            $table->double('collateral')->nullable();
            $table->double('volume')->default(0);
            $table->timestamp('http_last_modified')->nullable();
            $table->timestamps();
            $table->primary(['generation_id', 'contract_id']);
            $table->foreign('generation_id')->references('id')->on('public_contract_generations')->cascadeOnDelete();
        });

        Schema::create('public_contract_snapshot_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('generation_id');
            $table->unsignedBigInteger('contract_id')->index();
            $table->unsignedInteger('type_id')->index();
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_included')->default(true);
            $table->boolean('is_blueprint_copy')->nullable();
            $table->unsignedSmallInteger('material_efficiency')->nullable();
            $table->unsignedSmallInteger('time_efficiency')->nullable();
            $table->integer('runs')->nullable();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->unsignedBigInteger('item_id')->nullable();
            $table->timestamp('http_last_modified')->nullable();
            $table->timestamps();
            $table->index(['generation_id', 'contract_id', 'type_id'], 'pc_items_generation_contract_type_idx');
            $table->foreign('generation_id')->references('id')->on('public_contract_generations')->cascadeOnDelete();
        });

        // Copy the currently published tables in bounded batches so the new
        // readers continue to work before the first Everef refresh completes.
        DB::table('public_contract_generations')->insert([
            'id' => 1,
            'status' => 'published',
            'contract_count' => DB::table('public_contracts')->count(),
            'item_count' => DB::table('public_contract_items')->count(),
            'created_at' => now(),
            'published_at' => now(),
        ]);

        DB::table('public_contracts')->orderBy('contract_id')->chunk(500, function ($rows): void {
            DB::table('public_contract_snapshot_contracts')->insert($rows->map(function ($row): array {
                return ['generation_id' => 1] + (array) $row;
            })->all());
        });

        DB::table('public_contract_items')->orderBy('id')->chunk(500, function ($rows): void {
            DB::table('public_contract_snapshot_items')->insert($rows->map(function ($row): array {
                return ['generation_id' => 1] + (array) $row;
            })->all());
        });

        DB::table('public_contract_snapshot_state')->insert([
            'id' => 1,
            'active_generation_id' => 1,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('public_contract_snapshot_items');
        Schema::dropIfExists('public_contract_snapshot_contracts');
        Schema::dropIfExists('public_contract_snapshot_state');
        Schema::dropIfExists('public_contract_generations');
    }
};
